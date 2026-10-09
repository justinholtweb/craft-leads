<?php
/**
 * Targeting rules — page URLs, device type, visitor frequency — from the popup editor to the page.
 * Checked in the plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/targeting.php
 *
 * Until 5.1.0 the editor said targeting was coming "in a future update" and only a `pages` list
 * set in code was honoured. The server applies page rules; device and frequency rules travel with
 * the popup to the page script (tests/js covers what it does with them). Self-cleaning.
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
use justinholtweb\leads\services\Popups;

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
$templates = $root . '/templates';
$cleanup = ['popups' => [], 'users' => [], 'templates' => []];

register_shutdown_function(function() use (&$cleanup) {
    foreach ($cleanup['templates'] as $file) {
        @unlink($file);
    }
    foreach ($cleanup['popups'] as $id) {
        Craft::$app->getDb()->createCommand()->delete('{{%leads_stats}}', ['popupId' => $id])->execute();
        if ($popup = Popup::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($popup, true);
        }
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

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

$stored = static function(int $id): array {
    $value = (new Query())->select('targetingRules')->from('{{%leads_popups}}')->where(['id' => $id])->scalar();

    return is_string($value) ? (json_decode($value, true) ?: []) : (array)$value;
};

// A draft popup to edit; it goes live further down.
$popup = new Popup(['title' => "Leads targeting $run", 'popupType' => 'modal', 'heading' => "Targeted $run", 'popupStatus' => 'draft']);
Craft::$app->getElements()->saveElement($popup) or throw new RuntimeException(json_encode($popup->getErrors()));
$cleanup['popups'][] = $popup->id;

// Someone who can open Leads but not manage popups.
$viewer = new User(['username' => "leads-viewer-$run", 'email' => "leads-viewer-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-leads', 'leads:accessplugin']);
$cleanup['users'][] = $viewer;

[$adminHttp, $adminPost] = client('admin', 'claudepassword');
[, $viewerPost] = client($viewer->username, $password);

$save = static fn(callable $post, array $targeting, string $status = 'draft') => $post('leads/popups/save', [
    'popupId' => $popup->id, 'title' => $popup->title, 'popupType' => 'modal', 'heading' => "Targeted $run",
    'popupStatus' => $status, 'targetingRules' => $targeting,
]);

echo "\nThe editor\n";

check('the popup editor has the targeting fields, not a “future update” note', function() use ($adminHttp, $popup) {
    $body = (string)$adminHttp->get("index.php?p=admin/leads/popups/{$popup->id}")->getBody();
    $fields = [
        'targetingRules[pages]', 'targetingRules[excludePages]', 'targetingRules[devices][]', 'targetingRules[frequency]',
        'targetingRules[frequencyDays]', 'targetingRules[minPageViews]', 'targetingRules[visitor]', 'targetingRules[dismissDays]',
        'targetingRules[hideAfterConversion]',
    ];
    $missing = array_filter($fields, static fn($f) => !str_contains($body, 'name="' . $f . '"'));

    return $missing === [] && !str_contains($body, 'future update') ?: 'missing ' . implode(', ', $missing);
});

check('…with every device ticked for a popup that has no device rule', function() use ($adminHttp, $popup) {
    $body = (string)$adminHttp->get("index.php?p=admin/leads/popups/{$popup->id}")->getBody();

    return preg_match_all('/<input[^>]+name="targetingRules\[devices\]\[\]"[^>]+checked/', $body) === 3 ?: 'not all ticked';
});

check('a save stores the rules in their normalized shape', function() use ($save, $adminPost, $stored, $popup, $run) {
    $status = $save($adminPost, [
        'pages' => "*leads-targeting-$run*\r\n\r\n/elsewhere/*",
        'excludePages' => "*leads-targeting-$run-excluded*",
        'devices' => ['', 'desktop', 'tablet'],
        'frequency' => 'days', 'frequencyDays' => '14',
        'minPageViews' => '2', 'visitor' => 'returning',
        'dismissDays' => '3', 'hideAfterConversion' => '',
    ])->getStatusCode();
    $rules = $stored($popup->id);
    $expected = [
        'pages' => ["*leads-targeting-$run*", '/elsewhere/*'], 'excludePages' => ["*leads-targeting-$run-excluded*"],
        'devices' => ['desktop', 'tablet'], 'frequency' => 'days', 'frequencyDays' => 14, 'minPageViews' => 2,
        'visitor' => 'returning', 'hideAfterConversion' => false, 'dismissDays' => 3,
    ];
    ksort($rules);
    ksort($expected);

    return $rules === $expected ?: "status $status, stored " . json_encode($rules);
});

check('…and the editor shows them back', function() use ($adminHttp, $popup, $run) {
    $body = (string)$adminHttp->get("index.php?p=admin/leads/popups/{$popup->id}")->getBody();

    return str_contains($body, "*leads-targeting-$run*\n/elsewhere/*")
        && preg_match('/<option value="days" selected/', $body)
        && preg_match_all('/<input[^>]+name="targetingRules\[devices\]\[\]"[^>]+checked/', $body) === 2
        ?: 'not shown back';
});

foreach ([
    'no device ticked' => ['devices' => ''],
    'zero days between showings' => ['frequency' => 'days', 'frequencyDays' => '0'],
    'a negative page-view minimum' => ['minPageViews' => '-4'],
    'an unknown frequency' => ['frequency' => 'hourly'],
] as $what => $targeting) {
    check("a popup with $what won’t save", function() use ($save, $adminPost, $stored, $popup, $targeting) {
        $before = $stored($popup->id);
        $response = $save($adminPost, $targeting);

        return $response->getStatusCode() !== 200 || !(json_decode((string)$response->getBody(), true)['success'] ?? false)
            ? ($stored($popup->id) === $before ?: 'stored anyway: ' . json_encode($stored($popup->id)))
            : 'saved';
    });
}

echo "\nWho may change them\n";

check('someone who can’t manage popups can’t save targeting', function() use ($save, $viewerPost, $stored, $popup) {
    $before = $stored($popup->id);
    $status = $save($viewerPost, ['devices' => ['mobile']])->getStatusCode();

    return $status === 403 && $stored($popup->id) === $before ?: "status $status";
});

check('nor can an anonymous request', function() use ($popup, $stored) {
    $before = $stored($popup->id);
    $anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
    $status = $anon->post('index.php?p=admin/actions/leads/popups/save', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['popupId' => $popup->id, 'title' => 'x', 'targetingRules' => ['devices' => ['mobile']]],
    ])->getStatusCode();

    return in_array($status, [400, 403], true) && $stored($popup->id) === $before ?: "status $status";
});

echo "\nOn the page\n";

// Live, on this run's pages, every frequency rule a visitor could carry.
$save($adminPost, [
    'pages' => "*leads-targeting-$run*", 'excludePages' => "*leads-targeting-$run-excluded*",
    'devices' => ['', 'mobile'], 'frequency' => 'session', 'minPageViews' => '3', 'visitor' => 'new',
    'dismissDays' => '7', 'hideAfterConversion' => '1',
], 'active');

$page = "leads-targeting-$run";
foreach (['', '-excluded', '-other'] as $suffix) {
    file_put_contents("$templates/$page$suffix.twig", "<!doctype html><html><body><p>Page</p></body></html>");
    $cleanup['templates'][] = "$templates/$page$suffix.twig";
}
$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
$configs = static function(string $body): array {
    if (!preg_match('/window\._leadsConfig = \(window\._leadsConfig \|\| \[\]\)\.concat\((.*?)\);<\/script>/s', $body, $m)) {
        return [];
    }

    return json_decode($m[1], true) ?: [];
};
$ours = static fn(array $configs) => array_values(array_filter($configs, static fn($c) => ($c['id'] ?? null) === $popup->id))[0] ?? null;

check('the popup is on a page its rules include', function() use ($anon, $page, $configs, $ours) {
    return $ours($configs((string)$anon->get("index.php?p=$page")->getBody())) !== null ?: 'not injected';
});

check('…and not on one they exclude, though it matches the include too', function() use ($anon, $page, $configs, $ours) {
    return $ours($configs((string)$anon->get("index.php?p=$page-excluded")->getBody())) === null ?: 'injected';
});

check('the browser gets the device and frequency rules, and not the page patterns', function() use ($anon, $page, $configs, $ours) {
    $config = $ours($configs((string)$anon->get("index.php?p=$page")->getBody()));
    $expected = [
        'devices' => ['mobile'], 'frequency' => 'session', 'frequencyDays' => 7, 'minPageViews' => 3,
        'visitor' => 'new', 'hideAfterConversion' => true, 'dismissDays' => 7,
    ];

    return ($config['targeting'] ?? null) === $expected ?: json_encode($config['targeting'] ?? null);
});

check('the same HTML goes to a phone and a desktop — the device is decided in the browser, so caches are safe', function() use ($anon, $page, $configs, $ours) {
    $phone = $ours($configs((string)$anon->get("index.php?p=$page", ['headers' => ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148']])->getBody()));
    $desktop = $ours($configs((string)$anon->get("index.php?p=$page", ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129']])->getBody()));

    return $phone !== null && $phone == $desktop ?: 'differs';
});

check('with a page-view rule live, the script loads even where no popup does, so every page counts', function() use ($leads) {
    $popups = new Popups();
    $script = $leads->renderer->getInjectHtml([], $popups->countsVisits());

    return $popups->countsVisits() && str_contains($script, 'leads.js') && $leads->renderer->getInjectHtml([], false) === ''
        ?: 'not loaded';
});

check('…and the script itself is the one that decides', function() use ($anon, $page) {
    $body = (string)$anon->get("index.php?p=$page")->getBody();
    preg_match('/<script src="([^"]+leads\.js)"/', $body, $m);
    $js = isset($m[1]) ? (string)$anon->get(preg_replace('#^https?://[^/]+/#', '', $m[1]))->getBody() : '';

    return str_contains($js, 'LeadsTargeting') && str_contains($js, 'minPageViews') ?: 'old script: ' . ($m[1] ?? 'no src');
});

echo "\nInline forms\n";

$inline = new Popup([
    'title' => "Leads inline $run", 'slug' => "leads-inline-$run", 'popupType' => 'inline', 'templateKey' => 'minimal-inline',
    'heading' => "Inline $run", 'popupStatus' => 'active',
    'targetingRules' => ['excludePages' => ["*leads-targeting-$run-excluded*"], 'devices' => ['desktop']],
]);
Craft::$app->getElements()->saveElement($inline) or throw new RuntimeException(json_encode($inline->getErrors()));
$cleanup['popups'][] = $inline->id;
foreach (['inline', 'excluded-inline'] as $name) {
    file_put_contents("$templates/$page-$name.twig", "<!doctype html><html><body>{{ leadsInline('leads-inline-$run') }}</body></html>");
    $cleanup['templates'][] = "$templates/$page-$name.twig";
}

check('leadsInline() renders the form hidden, for the script to reveal on the right device', function() use ($anon, $page, $inline, $run) {
    $body = (string)$anon->get("index.php?p=$page-inline")->getBody();

    return (bool)preg_match('/<div data-leads-inline="' . $inline->id . '" hidden>.*Inline ' . $run . '/s', $body) ?: 'not rendered hidden';
});

check('…and leaves it out of a page its rules exclude', function() use ($anon, $page, $run) {
    $body = (string)$anon->get("index.php?p=$page-excluded-inline")->getBody();

    return !str_contains($body, "Inline $run") ?: 'rendered';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
