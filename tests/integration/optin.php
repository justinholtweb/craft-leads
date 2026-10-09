<?php
/**
 * The consent checkbox and double opt-in, from the popup editor to the confirmation link —
 * checked in the plugin-testing harness, partly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/optin.php
 *
 * Covers: the stored consent record and its wording, the confirmation email, the link (opening it
 * changes nothing; the POST confirms, once, before it expires; tokens are exact-match only),
 * unconfirmed sign-ups never reaching the integration, Lock's ledger getting confirmed consent
 * only, Toss's snapshot riding along when Toss manages consent, and the confirm endpoint's budget.
 * Mail goes to a file transport in this process. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\helpers\Db;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\helpers\ConsentBridge;
use justinholtweb\leads\helpers\Optin;
use justinholtweb\leads\models\Settings;
use justinholtweb\leads\Plugin;
use justinholtweb\leads\queue\jobs\SyncSubmissionJob;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();
$leads = Plugin::getInstance();
$db = Craft::$app->getDb();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$cleanup = ['popups' => [], 'emails' => []];
$mailDir = sys_get_temp_dir() . "/leads-optin-$run";

register_shutdown_function(function() use (&$cleanup, $db, $mailDir) {
    foreach ($cleanup['popups'] as $id) {
        $ids = (new Query())->select('id')->from('{{%leads_submissions}}')->where(['popupId' => $id])->column();
        foreach ($ids as $sid) {
            $db->createCommand()->delete('{{%queue}}', ['description' => "Syncing submission #$sid to email provider"])->execute();
        }
        $db->createCommand()->delete('{{%leads_submissions}}', ['popupId' => $id])->execute();
        $db->createCommand()->delete('{{%leads_stats}}', ['popupId' => $id])->execute();
        if ($popup = Popup::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($popup, true);
        }
    }
    if ($db->tableExists('{{%lock_consents}}')) {
        foreach ($cleanup['emails'] as $email) {
            $hashes = (new Query())->select('emailHash')->from('{{%lock_consents}}')->where(['email' => $email])->column();
            $db->createCommand()->delete('{{%lock_consents}}', ['email' => $email])->execute();
            if ($hashes !== [] && $db->tableExists('{{%lock_activity}}')) {
                $db->createCommand()->delete('{{%lock_activity}}', ['subjectHash' => $hashes])->execute();
            }
        }
    }
    foreach (glob("$mailDir/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($mailDir);
});

// Mail from this process goes to files; the last message is kept for the checks.
$mailer = Craft::$app->getMailer();
$mailer->useFileTransport = true;
$mailer->fileTransportPath = $mailDir;
$sent = [];
$mailer->on(BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$sent) {
    $sent[] = $event->message;
});

$row = static fn(int $id) => (new Query())->from('{{%leads_submissions}}')->where(['id' => $id])->one();
$queued = static fn(int $id) => (new Query())->from('{{%queue}}')->where(['description' => "Syncing submission #$id to email provider"])->exists();

$save = static function(array $attributes) use (&$cleanup, $run): Popup {
    $popup = new Popup($attributes + [
        'title' => "Leads opt-in $run",
        'popupType' => 'modal',
        'popupStatus' => 'active',
        'targetingRules' => ['pages' => ["*leads-optin-$run*"]],
    ]);
    Craft::$app->getElements()->saveElement($popup) or throw new RuntimeException(json_encode($popup->getErrors()));
    $cleanup['popups'][] = $popup->id;

    return Plugin::getInstance()->popups->getById($popup->id) ?? throw new RuntimeException('reload');
};

$wording = 'I agree to emails and the [privacy policy](/privacy). <b>no</b>';
// A public-looking webhook: the job is queued but nothing in this test runs the queue.
$integration = ['integrationProvider' => 'webhook', 'integrationSettings' => ['webhookUrl' => 'https://example.com/leads-optin']];

echo "\nSchema\n";

check('the migration added the popup and submission columns', function() use ($db, $leads) {
    $missing = [];
    if (!$db->columnExists('{{%leads_popups}}', 'consentSettings')) {
        $missing[] = 'consentSettings';
    }
    foreach (['consentGiven', 'consentText', 'consentVersion', 'consentedAt', 'consentEvidence', 'confirmTokenHash', 'confirmExpiresAt', 'confirmedAt'] as $column) {
        if (!$db->columnExists('{{%leads_submissions}}', $column)) {
            $missing[] = $column;
        }
    }
    $stored = Craft::$app->getProjectConfig()->get('plugins.leads.schemaVersion');

    return $missing === [] && $stored === $leads->schemaVersion ?: 'missing ' . implode(', ', $missing) . "; schema $stored";
});

echo "\nThe popup editor's rules\n";

check('a consent checkbox needs wording', function() {
    $popup = new Popup(['title' => 'x', 'consentSettings' => ['checkbox' => true, 'text' => '']]);

    return !$popup->validate(['consentSettings']) && $popup->hasErrors('consentSettings') ?: 'saved without wording';
});

check('“the provider confirms” is refused for a webhook, accepted for Mailchimp', function() {
    $webhook = new Popup(['title' => 'x', 'integrationProvider' => 'webhook', 'consentSettings' => ['doubleOptIn' => 'provider']]);
    $mailchimp = new Popup(['title' => 'x', 'integrationProvider' => 'mailchimp', 'consentSettings' => ['doubleOptIn' => 'provider']]);

    return !$webhook->validate(['consentSettings']) && $mailchimp->validate(['consentSettings']) ?: 'webhook ' . json_encode($webhook->getErrors()) . ' mailchimp ' . json_encode($mailchimp->getErrors());
});

check('settings: the email has to contain {link}', function() {
    $settings = new Settings(['confirmationBody' => 'Confirm please']);
    $ok = new Settings();

    return !$settings->validate(['confirmationBody']) && $ok->validate() ?: json_encode($settings->getErrors() + $ok->getErrors());
});

$optin = $save(['consentSettings' => ['checkbox' => true, 'required' => true, 'text' => $wording, 'doubleOptIn' => 'email']] + $integration);
$plain = $save(['title' => "Leads plain $run"] + $integration);
$noIntegration = $save(['title' => "Leads none $run", 'consentSettings' => ['checkbox' => true, 'text' => 'Yes please']]);

echo "\nWhat the popup renders\n";

check('a required checkbox, the wording escaped, its link a link', function() use ($leads, $optin) {
    $html = $leads->renderer->renderPopup($optin);

    return str_contains($html, 'name="consent"') && str_contains($html, ' required>')
        && str_contains($html, '<a href="/privacy" target="_blank" rel="noopener">privacy policy</a>')
        && str_contains($html, '&lt;b&gt;no&lt;/b&gt;') && !str_contains($html, '<b>no</b>')
        ?: substr($html, 0, 1500);
});

check('the success message asks them to check their inbox', function() use ($leads, $optin, $plain) {
    return str_contains($leads->renderer->renderPopup($optin), 'check your inbox')
        && str_contains($leads->renderer->renderPopup($plain), 'Thanks for subscribing!')
        && !str_contains($leads->renderer->renderPopup($plain), 'name="consent"')
        ?: 'wrong success text or stray checkbox';
});

// The public endpoints' budgets are per minute; start early in one.
if ((int)date('s') > 35) {
    sleep(61 - (int)date('s'));
}
$minute = intdiv(time(), 60);
foreach (['submit', 'confirm'] as $bucket) {
    Craft::$app->getCache()->delete(sprintf('leads:rate:%s:%s:%d', $bucket, sha1('127.0.0.1'), $minute));
    Craft::$app->getCache()->delete(sprintf('leads:rate:%s:*:%d', $bucket, $minute));
}

$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
$submit = static fn(array $body) => json_decode((string)$anon->post('index.php?p=actions/leads/submit/index', [
    'headers' => ['Accept' => 'application/json'],
    'json' => $body,
])->getBody(), true);

echo "\nSubmitting\n";

check('a required box left unticked is refused, with a reason, and nothing is stored', function() use ($submit, $optin, $run, $db) {
    $result = $submit(['popupId' => $optin->id, 'email' => "unticked-$run@example.com"]);
    $stored = (new Query())->from('{{%leads_submissions}}')->where(['email' => "unticked-$run@example.com"])->exists();

    return ($result['success'] ?? null) === false && !empty($result['error']) && !$stored ?: json_encode($result);
});

$httpEmail = "ticked-$run@example.com";
$cleanup['emails'][] = $httpEmail;

check('ticked: stored with its wording and version, awaiting confirmation, not queued', function() use ($submit, $optin, $httpEmail, $wording, $queued) {
    $result = $submit(['popupId' => $optin->id, 'email' => $httpEmail, 'consent' => '1', 'pageUrl' => 'https://example.com/leads-optin']);
    $row = (new Query())->from('{{%leads_submissions}}')->where(['email' => $httpEmail])->one();

    return ($result['success'] ?? null) === true && ($result['confirm'] ?? null) === true
        && $row && (int)$row['consentGiven'] === 1 && $row['consentText'] === $wording
        && $row['consentVersion'] === Optin::version($wording) && $row['consentedAt'] !== null
        && $row['syncStatus'] === 'unconfirmed' && strlen((string)$row['confirmTokenHash']) === 64
        && $row['confirmExpiresAt'] > Db::prepareDateForDb(new DateTime('+47 hours'))
        && !$queued((int)$row['id'])
        ?: json_encode([$result, $row]);
});

check('Toss’s snapshot is recorded when Toss manages consent, and only then', function() use ($httpEmail) {
    $evidence = (new Query())->select('consentEvidence')->from('{{%leads_submissions}}')->where(['email' => $httpEmail])->scalar();
    $evidence = is_string($evidence) ? json_decode($evidence, true) : $evidence;
    $active = ConsentBridge::tossIsActive();

    return $active
        ? (is_array($evidence['toss']['categories'] ?? null) && array_key_exists('decided', $evidence['toss']) ?: 'Toss is active but ' . json_encode($evidence))
        : ($evidence === null ?: 'Toss is not active but ' . json_encode($evidence));
});

check('without double opt-in, the sign-up is queued for its integration straight away', function() use ($submit, $plain, $run, $queued, &$cleanup) {
    $email = "plain-$run@example.com";
    $result = $submit(['popupId' => $plain->id, 'email' => $email, 'consent' => '1']);
    $row = (new Query())->from('{{%leads_submissions}}')->where(['email' => $email])->one();

    return ($result['success'] ?? null) === true && ($result['confirm'] ?? null) === false
        && $row && $row['syncStatus'] === 'pending' && $row['consentGiven'] === null && $queued((int)$row['id'])
        ?: json_encode([$result, $row]);
});

check('with no integration it is “not synced”, and nothing is queued', function() use ($submit, $noIntegration, $run, $queued) {
    $email = "none-$run@example.com";
    $result = $submit(['popupId' => $noIntegration->id, 'email' => $email]);
    $row = (new Query())->from('{{%leads_submissions}}')->where(['email' => $email])->one();

    return ($result['success'] ?? null) === true && $row && $row['syncStatus'] === 'none'
        && (int)$row['consentGiven'] === 0 && $row['consentText'] === null && !$queued((int)$row['id'])
        ?: json_encode([$result, $row]);
});

echo "\nThe confirmation email\n";

$email = "service-$run@example.com";
$cleanup['emails'][] = $email;
$sent = [];
$record = $leads->submissions->create($optin->id, $email, 'Ada', [], 'https://example.com/leads-optin-page', true);
$token = $leads->submissions->lastToken;

check('one email, to the address, with a link carrying the token', function() use (&$sent, $email, $token) {
    $message = $sent[0] ?? null;
    if (count($sent) !== 1 || $message === null) {
        return count($sent) . ' messages';
    }
    $symfony = $message->getSymfonyEmail();
    $text = (string)$symfony->getTextBody();
    $html = (string)$symfony->getHtmlBody();

    return array_keys($message->getTo()) === [$email] && Optin::isTokenShaped($token)
        && str_contains($text, 'leads/confirm?code=' . $token) && str_contains($html, '<a href=')
        && str_contains((string)$symfony->getSubject(), 'confirm')
        ?: "to " . json_encode($message->getTo()) . "\n$text";
});

check('the token itself is stored nowhere, only its hash', function() use ($record, $token, $row, $db) {
    $data = $row($record->id);

    return $data['confirmTokenHash'] === Optin::hash($token)
        && !(new Query())->from('{{%leads_submissions}}')->where(['confirmTokenHash' => $token])->exists()
        && !str_contains(json_encode($data), $token)
        ?: 'token found in the row';
});

check('a sync job run for an unconfirmed sign-up sends nothing', function() use ($record, $row) {
    (new SyncSubmissionJob(['submissionId' => $record->id]))->execute(Craft::$app->getQueue());
    $data = $row($record->id);

    return $data['syncStatus'] === 'unconfirmed' && $data['syncedAt'] === null ?: json_encode($data);
});

echo "\nThe confirmation link\n";

$jar = new CookieJar();
$browser = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false, 'cookies' => $jar]);
$page = $browser->get('index.php?p=leads/confirm&code=' . rawurlencode($token));
$body = (string)$page->getBody();

check('opening it shows a Confirm button and changes nothing (mail scanners open links)', function() use ($page, $body, $record, $row, $queued) {
    $data = $row($record->id);

    return $page->getStatusCode() === 200 && str_contains($body, 'Confirm your subscription') && str_contains($body, 'name="code"')
        && $data['confirmedAt'] === null && $data['syncStatus'] === 'unconfirmed' && !$queued($record->id)
        ?: $page->getStatusCode() . ' ' . substr(strip_tags($body), 0, 300) . ' ' . json_encode($data);
});

check('…not cached, not indexed, no Referer', function() use ($page, $body) {
    // Another plugin (SEOmatic in the harness) may add its own Referrer-Policy after ours, so the
    // page also says it in a meta tag, which the browser applies over the header.
    return str_contains($page->getHeaderLine('Cache-Control'), 'no-store')
        && str_contains($page->getHeaderLine('Referrer-Policy'), 'no-referrer')
        && str_contains($body, '<meta name="referrer" content="no-referrer">')
        && preg_match('/noindex|none/', $page->getHeaderLine('X-Robots-Tag')) === 1
        ?: json_encode($page->getHeaders());
});

check('a made-up, wildcard or truncated code gets the same “expired” page', function() use ($anon, $token) {
    foreach (['nope', '*', '%', 'not ' . substr($token, 4), substr($token, 0, 42), Optin::newToken(), Optin::hash($token)] as $code) {
        $response = $anon->get('index.php?p=leads/confirm&code=' . rawurlencode($code));
        if ($response->getStatusCode() !== 404 || !str_contains((string)$response->getBody(), 'This link has expired')) {
            return "$code → " . $response->getStatusCode();
        }
    }

    return true;
});

check('a POST without the CSRF token confirms nothing', function() use ($anon, $token, $record, $row) {
    $status = $anon->post('index.php?p=leads/confirm', ['form_params' => ['action' => 'leads/confirm/index', 'code' => $token]])->getStatusCode();

    return $status === 400 && $row($record->id)['confirmedAt'] === null ?: "status $status";
});

preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $body, $csrf);
$confirmPost = static fn(string $code) => $browser->post('index.php?p=leads/confirm', ['form_params' => [
    'action' => 'leads/confirm/index', 'code' => $code, 'CRAFT_CSRF_TOKEN' => $csrf[1] ?? '',
]]);

check('the button confirms: dated, token spent, queued for the integration', function() use ($confirmPost, $token, $record, $row, $queued) {
    $response = $confirmPost($token);
    $data = $row($record->id);

    return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), 'You’re subscribed')
        && $data['confirmedAt'] !== null && $data['confirmTokenHash'] === null && $data['syncStatus'] === 'pending' && $queued($record->id)
        ?: $response->getStatusCode() . ' ' . json_encode($data);
});

check('the link works once', function() use ($confirmPost, $token, $anon) {
    $again = $confirmPost($token);
    $open = $anon->get('index.php?p=leads/confirm&code=' . rawurlencode($token));

    return $again->getStatusCode() === 404 && $open->getStatusCode() === 404 ?: $again->getStatusCode() . ' / ' . $open->getStatusCode();
});

check('Lock’s ledger gets the confirmed consent, verified, with the wording', function() use ($email, $wording, $db) {
    if (!ConsentBridge::lockIsActive()) {
        return 'Lock is not installed in the harness';
    }
    $rows = (new Query())->from('{{%lock_consents}}')->where(['email' => $email])->all();
    $evidence = json_decode((string)($rows[0]['evidence'] ?? '{}'), true);

    return count($rows) === 1 && $rows[0]['purpose'] === 'marketing' && $rows[0]['state'] === 'granted'
        && ($evidence['verified'] ?? null) === true && ($evidence['text'] ?? null) === $wording
        && ($evidence['url'] ?? null) === 'https://example.com/leads-optin-page' && ($evidence['via'] ?? null) === 'leads'
        ?: json_encode($rows);
});

check('…and nothing for a sign-up that hasn’t confirmed', function() use ($httpEmail) {
    return !(new Query())->from('{{%lock_consents}}')->where(['email' => $httpEmail])->exists() ?: 'recorded before confirmation';
});

check('an unticked (optional) box confirms the address but records no consent in Lock', function() use ($leads, $run, $save, &$cleanup) {
    $popup = $save(['title' => "Leads optional $run", 'consentSettings' => ['checkbox' => true, 'text' => 'Optional', 'doubleOptIn' => 'email']]);
    $address = "optional-$run@example.com";
    $cleanup['emails'][] = $address;
    $record = $leads->submissions->create($popup->id, $address, null, [], null, false, false);
    $confirmed = $leads->submissions->confirm($leads->submissions->lastToken);

    return $confirmed !== null && (int)$confirmed->consentGiven === 0 && $confirmed->syncStatus === 'none'
        && !(new Query())->from('{{%lock_consents}}')->where(['email' => $address])->exists()
        ?: json_encode($confirmed?->toArray());
});

check('an expired link is refused, then purged', function() use ($leads, $optin, $run, $row, $db) {
    $record = $leads->submissions->create($optin->id, "expired-$run@example.com", null, [], null, true, false);
    $token = $leads->submissions->lastToken;
    $db->createCommand()->update('{{%leads_submissions}}', ['confirmExpiresAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['id' => $record->id])->execute();

    $found = $leads->submissions->findPendingByToken($token);
    $confirmed = $leads->submissions->confirm($token);
    $purged = $leads->submissions->purgeExpiredUnconfirmed();

    return $found === null && $confirmed === null && $purged >= 1 && !$row($record->id) ?: "purged $purged";
});

check('two confirmations racing: one wins', function() use ($leads, $optin, $run) {
    $record = $leads->submissions->create($optin->id, "race-$run@example.com", null, [], null, false, false);
    $token = $leads->submissions->lastToken;
    $pending = $leads->submissions->findPendingByToken($token);
    // The second caller read the row as pending too, then the first spent the token.
    $first = $leads->submissions->confirm($token);
    $second = $leads->submissions->confirm($token);

    return $pending !== null && $first !== null && $second === null ?: 'confirmed twice';
});

echo "\nThe control panel\n";

check('the export carries the consent columns', function() use ($leads, $optin) {
    $rows = $leads->submissions->exportSubmissions($optin->id);
    $confirmed = array_values(array_filter($rows, static fn($r) => $r['confirmedAt'] !== null));

    return $rows !== [] && array_key_exists('consentText', $rows[0]) && $confirmed !== [] ?: json_encode($rows[0] ?? null);
});

$admin = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$json = ['Accept' => 'application/json'];
$csrfFor = static fn() => (string)(json_decode((string)$admin->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
$admin->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrfFor()]]);

check('the editor shows the section and saves it, normalized', function() use ($admin, $json, $csrfFor, $plain) {
    $page = (string)$admin->get("index.php?p=admin/leads/popups/{$plain->id}")->getBody();
    $admin->post('index.php?p=admin/actions/leads/popups/save', ['headers' => $json, 'form_params' => [
        'popupId' => $plain->id, 'title' => $plain->title, 'popupStatus' => 'active',
        'integrationProvider' => 'webhook', 'integrationSettings' => ['webhookUrl' => 'https://example.com/leads-optin'],
        'targetingRules' => ['pages' => $plain->getTargeting()['pages']],
        'consentSettings' => ['checkbox' => '1', 'required' => '1', 'text' => ' Yes, email me ', 'doubleOptIn' => 'email', 'extra' => 'dropped'],
        'CRAFT_CSRF_TOKEN' => $csrfFor(),
    ]]);
    $stored = (new Query())->select('consentSettings')->from('{{%leads_popups}}')->where(['id' => $plain->id])->scalar();
    $stored = is_string($stored) ? json_decode($stored, true) : $stored;

    return str_contains($page, 'Consent &amp; Confirmation') && $stored === ['checkbox' => true, 'required' => true, 'text' => 'Yes, email me', 'doubleOptIn' => 'email']
        ?: 'stored ' . json_encode($stored);
});

check('the Submissions screen shows consent and “Awaiting confirmation”', function() use ($admin, $optin) {
    $body = (string)$admin->get("index.php?p=admin/leads/submissions&popupId={$optin->id}")->getBody();

    return str_contains($body, 'Awaiting confirmation') && str_contains($body, 'confirmed') && str_contains($body, '>Consent<') ?: substr(strip_tags($body), 0, 400);
});

check('the Settings screen has the double opt-in settings', function() use ($admin) {
    $body = (string)$admin->get('index.php?p=admin/leads/settings')->getBody();

    return str_contains($body, 'settings[confirmationBody]') && str_contains($body, 'settings[lockPurpose]') ?: 'missing fields';
});

echo "\nThe confirm endpoint’s budget\n";

check('more than 20 a minute from one address get 429', function() use ($anon) {
    $statuses = [];
    for ($i = 0; $i < 25; $i++) {
        $statuses[] = $anon->get('index.php?p=leads/confirm&code=x')->getStatusCode();
    }

    return in_array(429, $statuses, true) ?: implode(',', $statuses);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
