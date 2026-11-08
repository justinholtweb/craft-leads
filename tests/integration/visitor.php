<?php
/**
 * Audience and cart rules, site-template designs, and the exit-intent fallback — from the popup
 * editor to the page and the visitor action. Checked in the plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-leads/tests/integration/visitor.php
 *
 * Needs Craft Commerce with at least one available variant. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Category;
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
$templates = $root . '/templates';
$designs = "$templates/_leads";
$cleanup = ['popups' => [], 'users' => [], 'files' => [], 'dirs' => [], 'elements' => [], 'relations' => [], 'orders' => []];

register_shutdown_function(function() use (&$cleanup) {
    foreach ($cleanup['files'] as $file) {
        @unlink($file);
    }
    foreach ($cleanup['dirs'] as $dir) {
        @rmdir($dir);
    }
    foreach ($cleanup['relations'] as $where) {
        Craft::$app->getDb()->createCommand()->delete('{{%relations}}', $where)->execute();
    }
    foreach ($cleanup['popups'] as $id) {
        Craft::$app->getDb()->createCommand()->delete('{{%leads_stats}}', ['popupId' => $id])->execute();
        if ($popup = Popup::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($popup, true);
        }
    }
    foreach (array_reverse($cleanup['elements']) as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
    foreach ($cleanup['orders'] as $number) {
        if ($order = craft\commerce\elements\Order::find()->number($number)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($order, true);
        }
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

/** A browser with its own cookies; `$login` signs it in first. */
function client(?array $login = null): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($login !== null) {
        $response = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $login[0], 'password' => $login[1], 'CRAFT_CSRF_TOKEN' => $csrf()]]);
        if ($response->getStatusCode() !== 200) {
            echo "Could not sign in as {$login[0]}\n";
            exit(1);
        }
    }

    $post = static fn(string $action, array $params, bool $cp = true) => $http->post('index.php?p=' . ($cp ? 'admin/' : '') . "actions/$action", [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$http, $post];
}

$ask = static function(Client $http, string $query): array {
    $response = $http->get("index.php?p=actions/leads/visitor&$query", ['headers' => ['Accept' => 'application/json']]);

    return [$response, json_decode((string)$response->getBody(), true)];
};

[$adminHttp, $adminPost] = client(['admin', 'claudepassword']);

// A popup to edit, and a page for it.
$popup = new Popup(['title' => "Leads visitor $run", 'popupType' => 'modal', 'heading' => "Visitor $run", 'popupStatus' => 'draft']);
Craft::$app->getElements()->saveElement($popup) or throw new RuntimeException(json_encode($popup->getErrors()));
$cleanup['popups'][] = $popup->id;
$page = "leads-visitor-$run";
file_put_contents("$templates/$page.twig", '<!doctype html><html><body><p>page</p></body></html>');
$cleanup['files'][] = "$templates/$page.twig";

// Posted as the editor's own form is, to the editor's URL, so a refused save comes back as the
// editor with its errors.
$adminCsrf = static fn() => (string)(json_decode((string)$adminHttp->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
$save = static fn(array $fields, string $status = 'active') => $adminHttp->post("index.php?p=admin/leads/popups/{$popup->id}", [
    'form_params' => $fields + [
        'action' => 'leads/popups/save', 'CRAFT_CSRF_TOKEN' => $adminCsrf(),
        'popupId' => $popup->id, 'title' => $popup->title, 'popupType' => 'modal', 'heading' => "Visitor $run",
        'popupStatus' => $status, 'templateKey' => 'clean-modal', 'triggerType' => 'time', 'triggerValue' => '3',
        'targetingRules' => ['pages' => "*$page*"],
    ],
]);
$errors = static function($response): string {
    $body = (string)$response->getBody();
    preg_match_all('/<ul[^>]*class="errors"[^>]*>(.*?)<\/ul>/s', $body, $m);
    $found = trim(preg_replace('/\s+/', ' ', strip_tags(implode(' ', $m[1]))));

    // Nothing listed: say what came back instead, so a failure explains itself.
    return $found !== '' ? $found : 'status ' . $response->getStatusCode() . ': ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 300);
};
$config = static function() use ($page, $popup): ?array {
    $body = (string)(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]))->get("index.php?p=$page")->getBody();
    if (!preg_match('/window\._leadsConfig = \(window\._leadsConfig \|\| \[\]\)\.concat\((.*?)\);<\/script>/s', $body, $m)) {
        return null;
    }
    foreach (json_decode($m[1], true) ?: [] as $c) {
        if ($c['id'] === $popup->id) {
            return $c;
        }
    }

    return null;
};
$stored = static fn() => (new Query())->select(['targetingRules', 'templateKey', 'triggerValue'])->from('{{%leads_popups}}')->where(['id' => $popup->id])->one();

echo "\nThe visitor action\n";

check('a guest is a guest, with no cart, and gets no cookie or session for asking', function() use ($ask) {
    [$response, $data] = $ask(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]), 'need=user,cart&in=1,2');
    $cookies = $response->getHeader('Set-Cookie');

    return $data === ['user' => ['guest' => true, 'groups' => []], 'cart' => ['empty' => true, 'subtotal' => 0, 'qty' => 0, 'in' => []]]
        && $cookies === []
        ?: json_encode([$data, $cookies]);
});

check('it is never cached and never indexed', function() use ($ask) {
    [$response] = $ask(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]), 'need=user');

    return str_contains($response->getHeaderLine('Cache-Control'), 'no-store') && str_contains($response->getHeaderLine('Cache-Control'), 'private')
        && $response->getHeaderLine('X-Robots-Tag') === 'noindex'
        ?: $response->getHeaderLine('Cache-Control');
});

check('it answers only what it is asked', function() use ($ask) {
    [, $data] = $ask(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]), 'need=user');
    [, $none] = $ask(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]), 'need=everything');

    return array_keys($data) === ['user'] && $none === [] ?: json_encode([$data, $none]);
});

$member = new User(['username' => "leads-member-$run", 'email' => "leads-member-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($member, false);
Craft::$app->getUsers()->activateUser($member);
$subscribers = Craft::$app->getUserGroups()->getGroupByHandle('subscribers') ?? throw new RuntimeException('No subscribers group');
Craft::$app->getUsers()->assignUserToGroups($member->id, [$subscribers->id]);
$cleanup['users'][] = $member;
[$memberHttp] = client([$member->username, $password]);

check('a signed-in user, with their group handles', function() use ($ask, $memberHttp) {
    [, $data] = $ask($memberHttp, 'need=user');

    return $data === ['user' => ['guest' => false, 'groups' => ['subscribers']]] ?: json_encode($data);
});

// A cart holding a product in Clothing › T-shirts.
$group = Craft::$app->getCategories()->getGroupByHandle('category') ?? throw new RuntimeException('No category group');
$parent = new Category(['groupId' => $group->id, 'title' => "Clothing $run"]);
Craft::$app->getElements()->saveElement($parent) or throw new RuntimeException(json_encode($parent->getErrors()));
$cleanup['elements'][] = $parent;
$child = new Category(['groupId' => $group->id, 'title' => "T-shirts $run"]);
$child->setParentId($parent->id);
Craft::$app->getElements()->saveElement($child) or throw new RuntimeException(json_encode($child->getErrors()));
$cleanup['elements'][] = $child;
$other = new Category(['groupId' => $group->id, 'title' => "Lamps $run"]);
Craft::$app->getElements()->saveElement($other) or throw new RuntimeException(json_encode($other->getErrors()));
$cleanup['elements'][] = $other;

$variant = craft\commerce\elements\Variant::find()->status('enabled')->hasStock(true)->one() ?? throw new RuntimeException('No variant to buy');
$field = Craft::$app->getFields()->getFieldByHandle('category') ?? throw new RuntimeException('No category field');
Craft::$app->getDb()->createCommand()->insert('{{%relations}}', ['fieldId' => $field->id, 'sourceId' => $variant->getOwner()->id, 'targetId' => $child->id, 'sortOrder' => 1])->execute();
$cleanup['relations'][] = ['sourceId' => $variant->getOwner()->id, 'targetId' => $child->id];

[$shopper, $shopperPost] = client();
$added = $shopperPost('commerce/cart/update-cart', ['purchasableId' => $variant->id, 'qty' => 2], false);
$number = json_decode((string)$added->getBody(), true)['cart']['number'] ?? null;
if ($number) {
    $cleanup['orders'][] = $number;
}

check('a cart: not empty, its subtotal and quantity', function() use ($ask, $shopper, $variant) {
    [, $data] = $ask($shopper, 'need=cart');
    $cart = $data['cart'] ?? [];

    return $cart['empty'] === false && abs($cart['subtotal'] - 2 * $variant->getPrice()) < 0.01 && $cart['qty'] === 2 ?: json_encode($data);
});

check('…in the category its product is in, and that category’s parent, and not another', function() use ($ask, $shopper, $child, $parent, $other) {
    [, $data] = $ask($shopper, "need=cart&in={$parent->id},{$child->id},{$other->id}");
    $in = $data['cart']['in'] ?? null;
    sort($in);
    $expected = [$parent->id, $child->id];
    sort($expected);

    return $in === $expected ?: json_encode($data);
});

echo "\nThe editor\n";

check('the editor has the signed-in and cart rules', function() use ($adminHttp, $popup) {
    $body = (string)$adminHttp->get("index.php?p=admin/leads/popups/{$popup->id}")->getBody();

    return str_contains($body, 'targetingRules[audience]') && str_contains($body, 'targetingRules[userGroups]')
        && str_contains($body, 'targetingRules[cartBelow]') && str_contains($body, 'targetingRules[cartCategories]')
        ?: 'missing fields';
});

check('audience and cart rules are saved, and go to the page with where to ask', function() use ($save, $stored, $config, $parent) {
    $status = $save(['targetingRules' => ['pages' => '*leads-visitor-*', 'userGroups' => ['subscribers'], 'cart' => 'notEmpty', 'cartBelow' => '50', 'cartCategories' => ['', (string)$parent->id]]])->getStatusCode();
    $rules = json_decode((string)$stored()['targetingRules'], true);
    $c = $config();

    return $status === 302
        && $rules['audience'] === 'users' && $rules['userGroups'] === ['subscribers'] && $rules['cartBelow'] == 50 && $rules['cartCategories'] === [$parent->id]
        && $c['targeting']['needs'] === ['user', 'cart'] && $c['targeting']['cartIn'] === [$parent->id]
        && is_string($c['visitorUrl']) && str_contains($c['visitorUrl'], 'leads/visitor') && !str_contains($c['visitorUrl'], '://')
        ?: json_encode([$status, $rules, $c['targeting'] ?? null, $c['visitorUrl'] ?? null]);
});

check('a user group that doesn’t exist is refused', function() use ($save, $errors) {
    $response = $save(['targetingRules' => ['userGroups' => ["nope$GLOBALS[run]"]]]);

    return $response->getStatusCode() === 200 && str_contains($errors($response), 'There’s no user group') ?: $errors($response);
});

echo "\nDesigns from site templates\n";

if (!is_dir($designs)) {
    mkdir($designs);
    $cleanup['dirs'][] = $designs;
}
$design = "split-$run";
file_put_contents("$designs/$design.twig", <<<TWIG
{# A test design #}
<div class="leads-popup leads-modal split-design-$run" data-leads-id="{{ popup.id }}">
    <style>.split-design-$run { display: grid; }</style>
    <button data-leads-close aria-label="Close">×</button>
    <h2>{{ popup.heading }}</h2>
    {{ form }}
    <div class="leads-success" hidden>{{ success }}</div>
</div>
TWIG);
file_put_contents("$designs/broken-$run.twig", '<div>{{ popup.heading|nosuchfilter }}</div>');
file_put_contents("$designs/styled-$run.twig", '<style>p{}</style><div class="leads-popup"></div>');
file_put_contents("$designs/_partial-$run.twig", '<div></div>');
array_push($cleanup['files'], "$designs/$design.twig", "$designs/broken-$run.twig", "$designs/styled-$run.twig", "$designs/_partial-$run.twig");

check('the editor lists the site’s designs, but not its partials', function() use ($adminHttp, $popup, $design, $run) {
    $body = (string)$adminHttp->get("index.php?p=admin/leads/popups/{$popup->id}")->getBody();

    return str_contains($body, 'value="site:' . $design . '"') && str_contains($body, 'Site templates') && !str_contains($body, "_partial-$run")
        ?: 'not listed';
});

check('a popup with a site design renders it on the page, with the form wired for the script', function() use ($save, $config, $stored, $design, $run) {
    $status = $save(['templateKey' => "site:$design"])->getStatusCode();
    $html = $config()['html'] ?? '';

    return $status === 302 && $stored()['templateKey'] === "site:$design"
        && str_contains($html, "split-design-$run") && str_contains($html, 'data-leads-form') && str_contains($html, 'name="popupId"')
        && str_contains($html, 'Thanks for subscribing')
        ?: json_encode([$status, $stored()['templateKey'], substr($html, 0, 300)]);
});

check('a design with a Twig error is refused, saying what is wrong', function() use ($save, $errors, $run) {
    $response = $save(['templateKey' => "site:broken-$run"]);

    return $response->getStatusCode() === 200 && str_contains($errors($response), 'couldn’t be rendered') && str_contains($errors($response), 'nosuchfilter')
        ?: $errors($response);
});

check('so is one whose first element is a <style>', function() use ($save, $errors, $run) {
    $response = $save(['templateKey' => "site:styled-$run"]);

    return $response->getStatusCode() === 200 && str_contains($errors($response), 'root element') ?: $errors($response);
});

check('a design key is never a path', function() use ($save, $errors) {
    $response = $save(['templateKey' => 'site:../index']);
    $builtIn = $save(['templateKey' => '../settings/index']);

    return $response->getStatusCode() === 200 && $builtIn->getStatusCode() === 200 && str_contains($errors($builtIn), 'Choose one of the designs') ?: [$response->getStatusCode(), $builtIn->getStatusCode()];
});

echo "\nExit intent on phones\n";

check('an exit-intent popup stores its own fallback, not the time delay left over from before', function() use ($save, $stored, $config) {
    $save(['triggerType' => 'exit', 'triggerValue' => '3', 'exitFallback' => '']);
    $none = $stored()['triggerValue'];
    $save(['triggerType' => 'exit', 'triggerValue' => '3', 'exitFallback' => '20']);

    return $none === null && $stored()['triggerValue'] === '20' && ($config()['triggerValue'] ?? null) === '20'
        ?: json_encode([$none, $stored()['triggerValue']]);
});

check('a fallback that isn’t a number of seconds is refused', function() use ($save, $errors) {
    $response = $save(['triggerType' => 'exit', 'exitFallback' => '9000']);

    return $response->getStatusCode() === 200 && str_contains($errors($response), 'from 1 to 600') ?: $errors($response);
});

check('a popup made in code with an exit trigger has no fallback unless it’s given one', function() {
    $made = new \justinholtweb\leads\elements\Popup(['triggerType' => 'exit']);

    return $made->triggerValue === null ?: var_export($made->triggerValue, true);
});

check('the editor still offers a new popup a 3-second delay', function() use ($adminHttp) {
    $body = (string)$adminHttp->get('index.php?p=admin/leads/popups/new')->getBody();

    return (bool)preg_match('/<input[^>]*name="triggerValue"[^>]*value="3"/', $body) ?: 'no triggerValue of 3 in the new-popup editor';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
