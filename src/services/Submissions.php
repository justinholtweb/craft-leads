<?php

namespace justinholtweb\leads\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\enums\SyncStatus;
use justinholtweb\leads\events\SubmissionEvent;
use justinholtweb\leads\helpers\ConsentBridge;
use justinholtweb\leads\helpers\Optin;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\queue\jobs\SyncSubmissionJob;
use justinholtweb\leads\records\SubmissionRecord;

class Submissions extends Component
{
    /** Raised after a submission is stored — confirmed or not yet. `$event->submission`. */
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';

    /** Raised after a double opt-in sign-up is confirmed, before it is synced. `$event->submission`. */
    public const EVENT_AFTER_CONFIRM = 'afterConfirm';

    /**
     * The token in the last confirmation email this request sent, for tests and for code that
     * sends its own email instead ({@see create()} with `$sendEmail = false`). Never stored.
     */
    public ?string $lastToken = null;

    public function submit(int $popupId, string $email, ?string $name = null, array $customFields = [], ?string $pageUrl = null, ?bool $consent = null): bool
    {
        return $this->create($popupId, $email, $name, $customFields, $pageUrl, $consent) !== null;
    }

    /**
     * Stores a submission and starts it on its way.
     *
     * With the popup's double opt-in set to email, the submission waits as `unconfirmed` and a
     * confirmation link is emailed; nothing reaches the email provider or the webhook until that
     * link is used. Otherwise it is queued for the popup's integration straight away.
     *
     * `$consent` is whether the consent box was ticked. It is ignored for a popup that doesn't
     * show one. The caller checks a required box; this records whatever it is told.
     */
    public function create(int $popupId, string $email, ?string $name = null, array $customFields = [], ?string $pageUrl = null, ?bool $consent = null, bool $sendEmail = true): ?SubmissionRecord
    {
        $popup = Plugin::getInstance()->popups->getById($popupId);
        $settings = $popup?->getConsent() ?? Optin::normalize([]);
        $now = new DateTime();

        $record = new SubmissionRecord();
        $record->popupId = $popupId;
        $record->email = $email;
        $record->name = $name;
        // Assign the raw array — the json() column encodes it once. Pre-encoding
        // here would double-encode (Craft binds arrays to json columns itself).
        $record->customFields = !empty($customFields) ? $customFields : null;
        // A console command or queue job has no visitor: no address, browser or referrer.
        $request = Craft::$app->getRequest();
        $web = $request instanceof \craft\web\Request;
        $record->ipAddress = $web ? $request->getUserIP() : null;
        $record->userAgent = $web ? $request->getUserAgent() : null;
        $record->pageUrl = $pageUrl ?? ($web ? $request->getReferrer() : null) ?? '';

        // The wording is copied, not referenced: the popup can be reworded tomorrow, and the
        // question in two years is what *this* person was shown.
        if ($settings['checkbox']) {
            $record->consentGiven = $consent === true;
            if ($consent === true) {
                $record->consentText = $settings['text'];
                $record->consentVersion = Optin::version($settings['text']);
                $record->consentedAt = Db::prepareDateForDb($now);
            }
        }

        $toss = ConsentBridge::tossSnapshot();
        $record->consentEvidence = $toss !== null ? ['toss' => $toss] : null;

        $token = null;
        if ($settings['doubleOptIn'] === Optin::MODE_EMAIL) {
            $token = Optin::newToken();
            $hours = max(1, Plugin::getInstance()->getSettings()->confirmationExpiryHours);
            $record->confirmTokenHash = Optin::hash($token);
            $record->confirmExpiresAt = Db::prepareDateForDb((clone $now)->add(new DateInterval("PT{$hours}H")));
            $record->syncStatus = SyncStatus::Unconfirmed->value;
        } else {
            $record->syncStatus = $popup?->integrationProvider ? SyncStatus::Pending->value : SyncStatus::None->value;
        }

        if (!$record->save()) {
            return null;
        }

        // Record conversion stat
        Plugin::getInstance()->analytics->recordConversion($popupId);

        if ($token !== null) {
            $this->lastToken = $token;
            if ($sendEmail && $popup !== null) {
                $this->sendConfirmation($record, $popup, $token);
            }
        } else {
            $this->queueSync($record);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SUBMIT)) {
            $this->trigger(self::EVENT_AFTER_SUBMIT, new SubmissionEvent(['submission' => $record]));
        }

        return $record;
    }

    /**
     * Emails the confirmation link. The body is the plain-text setting with its placeholders
     * filled in — not Twig — and the HTML part is that text escaped, with the link made a link.
     */
    public function sendConfirmation(SubmissionRecord $record, Popup $popup, string $token): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $defaults = new \justinholtweb\leads\models\Settings();
        $link = $this->confirmUrl($token);
        $vars = [
            '{siteName}' => Craft::$app->getSites()->getCurrentSite()->getName(),
            '{popup}' => (string)$popup->title,
            '{hours}' => (string)max(1, $settings->confirmationExpiryHours),
        ];

        $subject = strtr(trim($settings->confirmationSubject) ?: $defaults->confirmationSubject, $vars);
        $body = trim($settings->confirmationBody) ?: $defaults->confirmationBody;
        $text = strtr($body, $vars + ['{link}' => $link]);
        $html = nl2br(strtr(Html::encode($body), array_map([Html::class, 'encode'], $vars) + [
            '{link}' => Html::a(Html::encode($link), $link),
        ]), false);

        try {
            $sent = Craft::$app->getMailer()->compose()
                ->setTo($record->email)
                ->setSubject($subject)
                ->setTextBody($text)
                ->setHtmlBody($html)
                ->send();
        } catch (\Throwable $e) {
            Craft::error("Leads couldn’t send the confirmation email for submission {$record->id}: {$e->getMessage()}", 'leads');

            return false;
        }

        if (!$sent) {
            Craft::error("Leads couldn’t send the confirmation email for submission {$record->id}.", 'leads');
        }

        return $sent;
    }

    /** The page a confirmation link opens, on the site the sign-up came from. */
    public function confirmUrl(string $token): string
    {
        // `code`, not `token`: Craft reads a `token` query param as its own preview token.
        return UrlHelper::siteUrl('leads/confirm', ['code' => $token]);
    }

    /**
     * The unconfirmed, unexpired submission a token belongs to, or null. Read-only — showing the
     * confirmation page changes nothing, so a mail scanner that opens the link confirms nobody.
     *
     * An exact match on the token's hash, after the token's shape is checked: never a LIKE, never
     * `Db::parseParam()`, which would read `*` and `not ` in a token as operators.
     */
    public function findPendingByToken(mixed $token): ?SubmissionRecord
    {
        if (!Optin::isTokenShaped($token)) {
            return null;
        }

        $record = SubmissionRecord::find()
            ->where(['confirmTokenHash' => Optin::hash($token)])
            ->andWhere(['confirmedAt' => null])
            ->andWhere(['>', 'confirmExpiresAt', Db::prepareDateForDb(new DateTime())])
            ->one();

        return $record instanceof SubmissionRecord ? $record : null;
    }

    /**
     * Confirms the sign-up a token belongs to: once, and only before it expires. Returns the
     * submission, or null for a token that is malformed, unknown, used or expired — the caller
     * can't tell which, and neither can whoever is guessing.
     *
     * The token is spent by a single conditional UPDATE, so two clicks racing each other confirm
     * once. Then, and only then, the submission goes to the popup's integration and, when Lock is
     * installed and the box was ticked, to Lock's consent ledger.
     */
    public function confirm(mixed $token): ?SubmissionRecord
    {
        $record = $this->findPendingByToken($token);

        if ($record === null) {
            return null;
        }

        $popup = Plugin::getInstance()->popups->getById($record->popupId);
        $now = Db::prepareDateForDb(new DateTime());
        $status = $popup?->integrationProvider ? SyncStatus::Pending->value : SyncStatus::None->value;

        $won = Craft::$app->getDb()->createCommand()->update(SubmissionRecord::tableName(), [
            'confirmedAt' => $now,
            'confirmTokenHash' => null,
            'syncStatus' => $status,
            'dateUpdated' => $now,
        ], [
            'and',
            ['id' => $record->id],
            ['confirmTokenHash' => Optin::hash((string)$token)],
            ['confirmedAt' => null],
            ['>', 'confirmExpiresAt', $now],
        ])->execute();

        if ($won !== 1) {
            return null;
        }

        $record->refresh();

        if ($this->hasEventHandlers(self::EVENT_AFTER_CONFIRM)) {
            $this->trigger(self::EVENT_AFTER_CONFIRM, new SubmissionEvent(['submission' => $record]));
        }

        if ($record->consentGiven) {
            $this->recordInLock($record, $popup);
        }

        $this->queueSync($record);

        return $record;
    }

    /**
     * Whether a submission may go to its integration yet. One that is waiting for its
     * confirmation may not, wherever the request to send it comes from.
     *
     * @param SubmissionRecord|array<string, mixed> $submission
     */
    public static function isReleasable(SubmissionRecord|array $submission): bool
    {
        $hash = $submission instanceof SubmissionRecord ? $submission->confirmTokenHash : ($submission['confirmTokenHash'] ?? null);
        $status = $submission instanceof SubmissionRecord ? $submission->syncStatus : ($submission['syncStatus'] ?? null);

        return $hash === null && $status !== SyncStatus::Unconfirmed->value;
    }

    /**
     * Unconfirmed sign-ups whose link has expired. They were never consented to, so they aren't
     * kept: removed when Craft collects garbage, and by this method. Returns how many went.
     */
    public function purgeExpiredUnconfirmed(): int
    {
        return Craft::$app->getDb()->createCommand()->delete(SubmissionRecord::tableName(), [
            'and',
            ['syncStatus' => SyncStatus::Unconfirmed->value],
            ['confirmedAt' => null],
            ['<=', 'confirmExpiresAt', Db::prepareDateForDb(new DateTime())],
        ])->execute();
    }

    private function queueSync(SubmissionRecord $record): void
    {
        if ($record->syncStatus !== SyncStatus::Pending->value || !self::isReleasable($record)) {
            return;
        }

        Craft::$app->getQueue()->push(new SyncSubmissionJob(['submissionId' => $record->id]));
    }

    private function recordInLock(SubmissionRecord $record, ?Popup $popup): void
    {
        $purpose = trim(Plugin::getInstance()->getSettings()->lockPurpose);

        if ($purpose === '' || !ConsentBridge::lockIsActive()) {
            return;
        }

        // MySQL hands a json column back decoded; MariaDB stores it as text.
        $evidence = $record->consentEvidence;
        $evidence = is_string($evidence) ? Json::decodeIfJson($evidence) : $evidence;

        ConsentBridge::recordInLock($record->email, $purpose, array_filter([
            'text' => $record->consentText,
            'textVersion' => $record->consentVersion,
            'url' => $record->pageUrl ?: null,
            'ip' => $record->ipAddress,
            'userAgent' => $record->userAgent,
            'verified' => true,
            'method' => 'double opt-in',
            'via' => 'leads',
            'popup' => $popup ? ['id' => $popup->id, 'title' => (string)$popup->title] : ['id' => $record->popupId],
            'submissionId' => $record->id,
            'consentedAt' => $record->consentedAt,
            'confirmedAt' => $record->confirmedAt,
            'toss' => is_array($evidence) ? ($evidence['toss'] ?? null) : null,
        ], static fn($value) => $value !== null));
    }

    public function getSubmissions(?int $popupId = null, int $limit = 50, int $offset = 0): array
    {
        $query = (new Query())
            ->from('{{%leads_submissions}}')
            ->orderBy('dateCreated DESC')
            ->limit($limit)
            ->offset($offset);

        if ($popupId) {
            $query->andWhere(['popupId' => $popupId]);
        }

        return $query->all();
    }

    public function getTotalSubmissions(?int $popupId = null): int
    {
        $query = (new Query())
            ->from('{{%leads_submissions}}');

        if ($popupId) {
            $query->andWhere(['popupId' => $popupId]);
        }

        return (int)$query->count();
    }

    public function deleteSubmission(int $id): bool
    {
        $record = SubmissionRecord::findOne($id);
        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    public function exportSubmissions(?int $popupId = null): array
    {
        $query = (new Query())
            ->select(['email', 'name', 'customFields', 'pageUrl', 'syncStatus', 'consentGiven', 'consentText', 'consentVersion', 'consentedAt', 'confirmedAt', 'dateCreated'])
            ->from('{{%leads_submissions}}')
            ->orderBy('dateCreated DESC');

        if ($popupId) {
            $query->andWhere(['popupId' => $popupId]);
        }

        return $query->all();
    }
}
