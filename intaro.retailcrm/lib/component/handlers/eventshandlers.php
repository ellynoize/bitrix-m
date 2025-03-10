<?php

/**
 * @category Integration
 * @package  Intaro\RetailCrm\Component\Loyalty
 * @author   RetailCRM <integration@retailcrm.ru>
 * @license  MIT
 * @link     http://retailcrm.ru
 * @see      http://retailcrm.ru/docs
 */

namespace Intaro\RetailCrm\Component\Handlers;

IncludeModuleLangFile(__FILE__);

use Bitrix\Main\Event;
use Bitrix\Main\HttpRequest;
use Bitrix\Sale\Order;
use Intaro\RetailCrm\Component\Builder\Bitrix\LoyaltyDataBuilder;
use Intaro\RetailCrm\Component\ConfigProvider;
use Intaro\RetailCrm\Component\ServiceLocator;
use Intaro\RetailCrm\Model\Api\Response\Order\Loyalty\OrderLoyaltyApplyResponse;
use Intaro\RetailCrm\Repository\UserRepository;
use Intaro\RetailCrm\Service\LoyaltyService;
use Intaro\RetailCrm\Service\LoyaltyAccountService;
use Intaro\RetailCrm\Service\CustomerService;
use Intaro\RetailCrm\Service\OrderLoyaltyDataService;
use Intaro\RetailCrm\Service\Utils;
use Logger;
use RetailCrmEvent;
use Throwable;

/**
 * Class EventsHandlers
 *
 * @package Intaro\RetailCrm\Component\Loyalty
 */
class EventsHandlers
{
    public static $disableSaleHandler = false;

    /**
     * EventsHandlers constructor.
     */
    public function __construct()
    {
        IncludeModuleLangFile(__FILE__);
    }

    /**
     * Обработчик события, вызываемого при обновлении еще не сохраненного заказа
     *
     * Модифицирует данные $arResult с учетом привилегий покупателя по Программе лояльности
     *
     * @param \Bitrix\Sale\Order       $order
     * @param array                    $arUserResult
     * @param \Bitrix\Main\HttpRequest $request
     * @param array                    $arParams
     * @param array                    $arResult
     */
    public static function OnSaleComponentOrderResultPreparedHandler(
        Order $order,
        array $arUserResult,
        HttpRequest $request,
        array $arParams,
        array &$arResult
    ): void {
        if (ConfigProvider::getLoyaltyProgramStatus() === 'Y') {
            $bonusInput           = (float) $request->get('bonus-input');
            $availableBonuses     = (float) $request->get('available-bonuses');
            $chargeRate           = (float) $request->get('charge-rate');
            $loyaltyDiscountInput = (float) $request->get('loyalty-discount-input');
            $calculateItemsInput  = $request->get('calculate-items-input');
            $bonusDiscount        = round($bonusInput * $chargeRate, 2);

            if ($bonusInput > $availableBonuses) {
                $arResult['LOYALTY']['ERROR'] = GetMessage('BONUS_ERROR_MSG');

                return;
            }

            $jsDataTotal = &$arResult['JS_DATA']['TOTAL'];

            $isWriteOffAvailable = $bonusInput > 0
                && $availableBonuses > 0
                && $jsDataTotal['ORDER_TOTAL_PRICE'] >= $bonusDiscount + $loyaltyDiscountInput
            ;

            if ($isWriteOffAvailable || $loyaltyDiscountInput > 0) {
                $jsDataTotal['ORDER_TOTAL_PRICE']
                                                        -= round($bonusDiscount + $loyaltyDiscountInput, 2);
                $jsDataTotal['ORDER_TOTAL_PRICE_FORMATED']
                                                        = number_format($jsDataTotal['ORDER_TOTAL_PRICE'], 0, ',', ' ')
                    . ' ' . GetMessage('RUB');
                $jsDataTotal['BONUS_PAYMENT']           = $bonusDiscount;
                $jsDataTotal['DISCOUNT_PRICE']          += $bonusDiscount + $loyaltyDiscountInput;
                $jsDataTotal['DISCOUNT_PRICE_FORMATED'] = $jsDataTotal['DISCOUNT_PRICE'] . ' ' . GetMessage('RUB');
                $jsDataTotal['ORDER_PRICE_FORMATED']
                                                        = $jsDataTotal['ORDER_PRICE'] - $loyaltyDiscountInput . ' ' . GetMessage('RUB');
                $oldItems                               = json_decode(htmlspecialchars_decode($calculateItemsInput), true);

                /** @var LoyaltyService $service */
                $service = ServiceLocator::get(LoyaltyService::class);
                $calculate = $service->getLoyaltyCalculate($arResult['BASKET_ITEMS'], $bonusInput);

                if ($calculate->success) {
                    $jsDataTotal['WILL_BE_CREDITED'] = $calculate->order->bonusesCreditTotal;
                }

                if ($calculateItemsInput !== null) {
                    foreach ($arResult['JS_DATA']['GRID']['ROWS'] as $key => &$item) {
                        $item['data']['SUM_NUM'] = $oldItems[$key]['SUM_NUM'];
                        $item['data']['SUM']     = $item['data']['SUM_NUM'] . GetMessage('RUB');
                    }
                }

                unset($item);
            }
        }
    }

    /**
     * Обработчик события, вызываемого ПОСЛЕ сохранения заказа (OnSaleOrderSaved)
     *
     * @param \Bitrix\Main\Event $event
     */
    public static function OnSaleOrderSavedHandler(Event $event): void
    {
        if (self::$disableSaleHandler === true) {
            return;
        }

        try {
            /** @var Order $order */
            $order = $event->getParameter('ENTITY');

            $isBonusInput = (
                !empty($_POST['bonus-input'])
                && !empty($_POST['available-bonuses'])
            );

            $isDataForLoyaltyDiscount = isset($_POST['calculate-items-input'], $_POST['loyalty-discount-input']);

            if (!($isDataForLoyaltyDiscount || $isBonusInput) ) {
                return;
            }

            /* @var LoyaltyService $loyaltyService */
            $loyaltyService = ServiceLocator::get(LoyaltyService::class);

            /* @var OrderLoyaltyDataService $orderLoyaltyDataService */
            $orderLoyaltyDataService = ServiceLocator::get(OrderLoyaltyDataService::class);

            $bonusFloat = (float) $_POST['bonus-input'];

            /** @var bool $isNewOrder */
            $isNewOrder                 = $event->getParameter('IS_NEW');
            $isLoyaltyOn                = ConfigProvider::getLoyaltyProgramStatus() === 'Y';
            $isBonusesIssetAndAvailable = $isBonusInput && (float) $_POST['available-bonuses'] >= $bonusFloat;

            /** @var array $calculateItemsInput */
            $calculateItemsInput = $isDataForLoyaltyDiscount
                ? json_decode(htmlspecialchars_decode($_POST['calculate-items-input']), true)
                : [];

            if ($isNewOrder && $isLoyaltyOn && ($isDataForLoyaltyDiscount || $isBonusInput)) {
                self::$disableSaleHandler = true;

                $hlInfoBuilder = new LoyaltyDataBuilder();
                $hlInfoBuilder->setOrder($order);

                $discountInput            = isset($_POST['loyalty-discount-input'])
                    ? (float) $_POST['loyalty-discount-input']
                    : 0;

                $loyaltyBonusMsg = 0;
                $applyBonusResponse = null;

                //Если есть бонусы
                if ($isBonusesIssetAndAvailable) {
                    $hlInfoBuilder->setApplyResponse($loyaltyService->applyBonusesInOrder($order, $bonusFloat));
                    $loyaltyBonusMsg = $bonusFloat;
                    $hlInfoBuilder->setBonusCountTotal($bonusFloat);
                }

                //Если бонусов нет, но скидка по ПЛ есть
                if (
                    ($isDataForLoyaltyDiscount && !$isBonusInput)
                ) {
                    $loyaltyService->saveDiscounts($order, $calculateItemsInput);
                }

                $orderLoyaltyDataService->saveBonusAndDiscToOrderProps(
                    $order->getPropertyCollection(),
                    $discountInput,
                    $loyaltyBonusMsg
                );

                $hlInfoBuilder->setCalculateItemsInput($calculateItemsInput);
                $orderLoyaltyDataService->saveLoyaltyInfoToHl($hlInfoBuilder->build()->getResult());
                $order->save();

                self::$disableSaleHandler = false;
            }
        } catch (Throwable $exception) {
            Logger::getInstance()->write(GetMessage('CAN_NOT_SAVE_ORDER') . $exception->getMessage(), 'uploadApiErrors');
        }
    }
}
