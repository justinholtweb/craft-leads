<?php

namespace justinholtweb\leads\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;

/**
 * What Leads' cart rules need to know about the visitor's Craft Commerce cart.
 *
 * Commerce's classes aren't named here. It is reached through Craft's plugin registry after
 * `isPluginEnabled()`, as `ConsentBridge` reaches Toss and Lock, so a site without Commerce never
 * loads a class that doesn't exist.
 *
 * The cart is only ever *looked at*: `peekCart()` (Commerce 5.7) finds an existing cart without
 * creating one, setting its cookie or starting a session. A visitor with no cart gets no cart. On
 * older Commerce, a cart is loaded only when the session already names one.
 */
abstract class CartBridge
{
    public static function commerceIsActive(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * The visitor's cart: whether it's empty, its item subtotal and quantity, and which of `$in`
     * (category or structure entry IDs) its products are related to, directly or through a child.
     * An empty cart without Commerce, or when the cart can't be read.
     *
     * @param int[] $in
     * @return array{empty: bool, subtotal: float, qty: int, in: int[]}
     */
    public static function summary(array $in): array
    {
        $none = ['empty' => true, 'subtotal' => 0.0, 'qty' => 0, 'in' => []];
        $cart = self::peek();
        if ($cart === null) {
            return $none;
        }

        try {
            $lineItems = $cart->getLineItems();
            if (!$lineItems) {
                return $none;
            }

            $sources = [];
            foreach ($lineItems as $lineItem) {
                $purchasable = $lineItem->getPurchasable();
                // A variant answers with itself and its product, as for Commerce's own
                // discount category conditions.
                if ($purchasable !== null && method_exists($purchasable, 'getPromotionRelationSource')) {
                    foreach ((array)$purchasable->getPromotionRelationSource() as $id) {
                        $sources[] = (int)$id;
                    }
                }
            }

            return [
                'empty' => false,
                'subtotal' => round((float)$cart->getItemSubtotal(), 2),
                'qty' => (int)$cart->getTotalQty(),
                'in' => $in === [] ? [] : self::relatedAmong(array_values(array_unique(array_filter($sources))), $in),
            ];
        } catch (\Throwable $e) {
            Craft::warning('Leads could not read the visitor’s cart: ' . $e->getMessage(), 'leads');

            return $none;
        }
    }

    /**
     * Which of `$wanted` the elements in `$sources` are related to — directly, or through a
     * descendant in a structure (a product in “T-shirts” is in “Clothing” too).
     *
     * @param int[] $sources
     * @param int[] $wanted
     * @return int[]
     */
    private static function relatedAmong(array $sources, array $wanted): array
    {
        if ($sources === []) {
            return [];
        }

        $targets = (new Query())
            ->select(['targetId'])
            ->distinct()
            ->from(Table::RELATIONS)
            ->where(['sourceId' => $sources])
            ->column();
        $targets = array_map('intval', $targets);
        if ($targets === []) {
            return [];
        }

        $ancestors = (new Query())
            ->select(['a.elementId'])
            ->distinct()
            ->from(['d' => Table::STRUCTUREELEMENTS])
            ->innerJoin(['a' => Table::STRUCTUREELEMENTS], '[[a.structureId]] = [[d.structureId]] AND [[a.lft]] < [[d.lft]] AND [[a.rgt]] > [[d.rgt]]')
            ->where(['d.elementId' => $targets])
            ->andWhere(['not', ['a.elementId' => null]])
            ->column();

        $all = array_flip(array_merge($targets, array_map('intval', $ancestors)));

        return array_values(array_filter($wanted, static fn(int $id) => isset($all[$id])));
    }

    /**
     * The visitor's existing cart, or null. Never creates one.
     *
     * @return object|null A `craft\commerce\elements\Order`.
     */
    private static function peek(): ?object
    {
        if (!self::commerceIsActive()) {
            return null;
        }

        try {
            $commerce = Craft::$app->getPlugins()->getPlugin('commerce');
            if ($commerce === null || !method_exists($commerce, 'getCarts')) {
                return null;
            }
            $carts = $commerce->getCarts();
            if (!is_object($carts)) {
                return null;
            }

            if (method_exists($carts, 'peekCart')) {
                return $carts->peekCart();
            }

            // Before Commerce 5.7: only a session that already has a cart, so nobody gets one.
            return method_exists($carts, 'getHasSessionCartNumber') && $carts->getHasSessionCartNumber()
                ? $carts->getCart()
                : null;
        } catch (\Throwable $e) {
            Craft::warning('Leads could not look for the visitor’s cart: ' . $e->getMessage(), 'leads');

            return null;
        }
    }
}
