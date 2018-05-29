<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Controller;

use Eccube\Application;
use Eccube\Entity\Customer;
use Eccube\Entity\CustomerAddress;
use Eccube\Entity\Master\OrderItemType;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\OrderItem;
use Eccube\Entity\Shipping;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Front\ShoppingShippingType;
use Eccube\Form\Type\ShippingMultipleType;
use Eccube\Repository\Master\OrderItemTypeRepository;
use Eccube\Repository\Master\PrefRepository;
use Eccube\Service\ShoppingService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\HttpFoundation\Request;

class ShippingMultipleController extends AbstractShoppingController
{
    /**
     * @var PrefRepository
     */
    protected $prefRepository;

    /**
     * @var OrderItemTypeRepository
     */
    protected $orderItemTypeRepository;

    /**
     * @var ShoppingService
     */
    protected $shoppingService;

    /**
     * ShippingMultipleController constructor.
     *
     * @param PrefRepository $prefRepository
     * @param OrderItemTypeRepository $orderItemTypeRepository
     * @param ShoppingService $shoppingService
     */
    public function __construct(
        PrefRepository $prefRepository,
        OrderItemTypeRepository $orderItemTypeRepository,
        ShoppingService $shoppingService
    ) {
        $this->prefRepository = $prefRepository;
        $this->orderItemTypeRepository = $orderItemTypeRepository;
        $this->shoppingService = $shoppingService;
    }


    /**
     * 複数配送処理
     *
     * @Route("/shopping/shipping_multiple", name="shopping_shipping_multiple")
     * @Template("Shopping/shipping_multiple.twig")
     */
    public function index(Request $request)
    {
        // カートチェック
        $response = $this->forwardToRoute('shopping_check_to_cart');
        if ($response->isRedirection() || $response->getContent()) {
            return $response;
        }

        /** @var \Eccube\Entity\Order $Order */
        $Order = $this->shoppingService->getOrder(OrderStatus::PROCESSING);
        if (!$Order) {
            log_info('購入処理中の受注情報がないため購入エラー');
            $this->addError('front.shopping.order.error');

            return $this->redirectToRoute('shopping_error');
        }

        // 処理しやすいようにすべてのShippingItemをまとめる
        $OrderItems = $Order->getProductOrderItems();

        // Orderに含まれる商品ごとの数量を求める
        $ItemQuantitiesByClassId = [];
        foreach ($OrderItems as $item) {
            $itemId = $item->getProductClass()->getId();
            $quantity = $item->getQuantity();
            if (array_key_exists($itemId, $ItemQuantitiesByClassId)) {
                $ItemQuantitiesByClassId[$itemId] += $quantity;
            } else {
                $ItemQuantitiesByClassId[$itemId] = $quantity;
            }
        }

        // FormBuilder用に商品ごとにShippingItemをまとめる
        $OrderItemsForFormBuilder = [];
        $tmpAddedClassIds = [];
        foreach ($OrderItems as $item) {
            $itemId = $item->getProductClass()->getId();
            if (!in_array($itemId, $tmpAddedClassIds)) {
                $OrderItemsForFormBuilder[] = $item;
                $tmpAddedClassIds[] = $itemId;
            }
        }

        // Form生成
        $builder = $this->formFactory->createBuilder();
        $builder
            ->add('shipping_multiple', CollectionType::class, [
                'entry_type' => ShippingMultipleType::class,
                'data' => $OrderItemsForFormBuilder,
                'allow_add' => true,
                'allow_delete' => true,
            ]);
        // Event
        $event = new EventArgs(
            [
                'builder' => $builder,
                'Order' => $Order,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::FRONT_SHOPPING_SHIPPING_MULTIPLE_INITIALIZE, $event);

        $form = $builder->getForm();
        $form->handleRequest($request);

        $errors = [];
        if ($form->isSubmitted() && $form->isValid()) {
            log_info('複数配送設定処理開始', [$Order->getId()]);

            $data = $form['shipping_multiple'];

            // フォームの入力から、送り先ごとに商品の数量を集計する
            $arrOrderItemTemp = [];
            foreach ($data as $mulitples) {
                $OrderItem = $mulitples->getData();
                foreach ($mulitples as $items) {
                    foreach ($items as $item) {
                        $cusAddId = $this->getCustomerAddressId($item['customer_address']->getData());
                        $itemId = $OrderItem->getProductClass()->getId();
                        $quantity = $item['quantity']->getData();

                        if (isset($arrOrderItemTemp[$cusAddId]) && array_key_exists($itemId, $arrOrderItemTemp[$cusAddId])) {
                            $arrOrderItemTemp[$cusAddId][$itemId] = $arrOrderItemTemp[$cusAddId][$itemId] + $quantity;
                        } else {
                            $arrOrderItemTemp[$cusAddId][$itemId] = $quantity;
                        }
                    }
                }
            }

            // フォームの入力から、商品ごとの数量を集計する
            $itemQuantities = [];
            foreach ($arrOrderItemTemp as $FormItemByAddress) {
                foreach ($FormItemByAddress as $itemId => $quantity) {
                    if (array_key_exists($itemId, $itemQuantities)) {
                        $itemQuantities[$itemId] = $itemQuantities[$itemId] + $quantity;
                    } else {
                        $itemQuantities[$itemId] = $quantity;
                    }
                }
            }

            // 「Orderに含まれる商品ごとの数量」と「フォームに入力された商品ごとの数量」が一致しているかの確認
            // 数量が異なっているならエラーを表示する
            foreach ($ItemQuantitiesByClassId as $key => $value) {
                if (array_key_exists($key, $itemQuantities)) {
                    if ($itemQuantities[$key] != $value) {
                        $errors[] = ['message' => trans('shopping.multiple.quantity.diff')];

                        // 対象がなければエラー
                        log_info('複数配送設定入力チェックエラー', [$Order->getId()]);

                        return [
                            'form' => $form->createView(),
                            'OrderItems' => $OrderItemsForFormBuilder,
                            'compItemQuantities' => $ItemQuantitiesByClassId,
                            'errors' => $errors,
                        ];
                    }
                }
            }

            // -- ここから先がお届け先を再生成する処理 --

            // お届け先情報をすべて削除
            /** @var Shipping $Shipping */
            foreach ($Order->getShippings() as $Shipping) {
                foreach ($Shipping->getOrderItems() as $OrderItem) {
                    $Shipping->removeOrderItem($OrderItem);
                    $this->entityManager->remove($OrderItem);
                }
                $this->entityManager->remove($Shipping);
            }
            $this->entityManager->flush();

            // お届け先のリストを作成する
            $ShippingList = [];
            foreach ($data as $mulitples) {
                $OrderItem = $mulitples->getData();
                $ProductClass = $OrderItem->getProductClass();
                $Delivery = $OrderItem->getShipping()->getDelivery();
                $saleTypeId = $ProductClass->getSaleType()->getId();

                foreach ($mulitples as $items) {
                    foreach ($items as $item) {
                        $CustomerAddress = $this->getCustomerAddress($item['customer_address']->getData());
                        $cusAddId = $this->getCustomerAddressId($item['customer_address']->getData());

                        $Shipping = new Shipping();
                        $Shipping
                            ->setFromCustomerAddress($CustomerAddress)
                            ->setDelivery($Delivery);

                        $ShippingList[$cusAddId][$saleTypeId] = $Shipping;
                    }
                }
            }
            // お届け先のリストを保存
            foreach ($ShippingList as $ShippingListByAddress) {
                foreach ($ShippingListByAddress as $Shipping) {
                    $this->entityManager->persist($Shipping);
                }
            }

            $ProductOrderType = $this->orderItemTypeRepository->find(OrderItemType::PRODUCT);

            // お届け先に、配送商品の情報(OrderItem)を関連付ける
            foreach ($data as $mulitples) {
                /** @var OrderItem $OrderItem */
                $OrderItem = $mulitples->getData();
                $ProductClass = $OrderItem->getProductClass();
                $Product = $OrderItem->getProduct();
                $saleTypeId = $ProductClass->getSaleType()->getId();
                $productClassId = $ProductClass->getId();

                foreach ($mulitples as $items) {
                    foreach ($items as $item) {
                        $cusAddId = $this->getCustomerAddressId($item['customer_address']->getData());

                        // お届け先から商品の数量を取得
                        $quantity = 0;
                        if (isset($arrOrderItemTemp[$cusAddId]) && array_key_exists($productClassId, $arrOrderItemTemp[$cusAddId])) {
                            $quantity = $arrOrderItemTemp[$cusAddId][$productClassId];
                            unset($arrOrderItemTemp[$cusAddId][$productClassId]);
                        } else {
                            // この配送先には送る商品がないのでスキップ（通常ありえない）
                            continue;
                        }

                        // 関連付けるお届け先のインスタンスを取得
                        $Shipping = $ShippingList[$cusAddId][$saleTypeId];

                        // インスタンスを生成して保存
                        $OrderItem = new OrderItem();
                        $OrderItem->setShipping($Shipping)
                            ->setOrder($Order)
                            ->setProductClass($ProductClass)
                            ->setProduct($Product)
                            ->setProductName($Product->getName())
                            ->setProductCode($ProductClass->getCode())
                            ->setPrice($ProductClass->getPrice02())
                            ->setQuantity($quantity)
                            ->setOrderItemType($ProductOrderType);

                        $ClassCategory1 = $ProductClass->getClassCategory1();
                        if (!is_null($ClassCategory1)) {
                            $OrderItem->setClasscategoryName1($ClassCategory1->getName());
                            $OrderItem->setClassName1($ClassCategory1->getClassName()->getName());
                        }
                        $ClassCategory2 = $ProductClass->getClassCategory2();
                        if (!is_null($ClassCategory2)) {
                            $OrderItem->setClasscategoryName2($ClassCategory2->getName());
                            $OrderItem->setClassName2($ClassCategory2->getClassName()->getName());
                        }
                        $Shipping->addOrderItem($OrderItem);
                        $this->entityManager->persist($OrderItem);
                    }
                }
            }

            // 送料を計算（お届け先ごと）
            foreach ($ShippingList as $data) {
                // data is product type => shipping
                foreach ($data as $Shipping) {
                    // 配送料金の設定
                    $this->shoppingService->setShippingDeliveryFee($Shipping);
                }
            }

            // 合計金額の再計算
            $flowResult = $this->executePurchaseFlow($Order);
            if ($flowResult->hasWarning() || $flowResult->hasError()) {
                return $this->redirectToRoute('shopping_error');
            }

            // 配送先を更新
            $this->entityManager->flush();

            $event = new EventArgs(
                [
                    'form' => $form,
                    'Order' => $Order,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::FRONT_SHOPPING_SHIPPING_MULTIPLE_COMPLETE, $event);

            log_info('複数配送設定処理完了', [$Order->getId()]);

            return $this->redirectToRoute('shopping');
        }

        return [
            'form' => $form->createView(),
            'OrderItems' => $OrderItemsForFormBuilder,
            'compItemQuantities' => $ItemQuantitiesByClassId,
            'errors' => $errors,
        ];
    }

    /**
     * 複数配送設定時の新規お届け先の設定
     *
     * @Route("/shopping/shipping_multiple_edit", name="shopping_shipping_multiple_edit")
     * @Template("Shopping/shipping_multiple_edit.twig")
     */
    public function shippingMultipleEdit(Request $request)
    {
        // カートチェック
        $response = $this->forwardToRoute('shopping_check_to_cart');
        if ($response->isRedirection() || $response->getContent()) {
            return $response;
        }

        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $CustomerAddress = new CustomerAddress();
        $builder = $this->formFactory->createBuilder(ShoppingShippingType::class, $CustomerAddress);

        $event = new EventArgs(
            [
                'builder' => $builder,
                'Customer' => $Customer,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::FRONT_SHOPPING_SHIPPING_MULTIPLE_EDIT_INITIALIZE, $event);

        $form = $builder->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            log_info('複数配送のお届け先追加処理開始');

            if ($this->isGranted('ROLE_USER')) {
                $CustomerAddresses = $Customer->getCustomerAddresses();

                $count = count($CustomerAddresses);
                if ($count >= $this->eccubeConfig['eccube_deliv_addr_max']) {
                    return [
                        'error' => trans('delivery.text.error.max_delivery_address'),
                        'form' => $form->createView(),
                    ];
                }

                $CustomerAddress->setCustomer($Customer);
                $this->entityManager->persist($CustomerAddress);
                $this->entityManager->flush($CustomerAddress);
            } else {
                // 非会員用のセッションに追加
                $CustomerAddresses = $this->session->get($this->sessionCustomerAddressKey);
                $CustomerAddresses = unserialize($CustomerAddresses);
                $CustomerAddresses[] = $CustomerAddress;
                $this->session->set($this->sessionCustomerAddressKey, serialize($CustomerAddresses));
            }

            $event = new EventArgs(
                [
                    'form' => $form,
                    'CustomerAddresses' => $CustomerAddresses,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::FRONT_SHOPPING_SHIPPING_MULTIPLE_EDIT_COMPLETE, $event);

            log_info('複数配送のお届け先追加処理完了');

            return $this->redirectToRoute('shopping_shipping_multiple');
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * フォームの情報からお届け先のインデックスを返す
     *
     * @param mixed $CustomerAddressData
     *
     * @return int
     */
    private function getCustomerAddressId($CustomerAddressData)
    {
        if ($CustomerAddressData instanceof CustomerAddress) {
            return $CustomerAddressData->getId();
        } else {
            return $CustomerAddressData;
        }
    }

    /**
     * フォームの情報からお届け先のインスタンスを返す
     *
     * @param mixed $CustomerAddressData
     *
     * @return CustomerAddress
     */
    private function getCustomerAddress($CustomerAddressData)
    {
        if (is_int($CustomerAddressData)) {
            $CustomerAddress = $this->entityManager->find(CustomerAddress::class, $CustomerAddressData);
            if ($CustomerAddress) {
                return $CustomerAddress;
            }
        }

        $cusAddId = $CustomerAddressData;
        $customerAddresses = $this->session->get($this->sessionCustomerAddressKey);
        $customerAddresses = unserialize($customerAddresses);

        $CustomerAddress = $customerAddresses[$cusAddId];
        $pref = $this->prefRepository->find($CustomerAddress->getPref()->getId());
        $CustomerAddress->setPref($pref);

        return $CustomerAddress;
    }
}
