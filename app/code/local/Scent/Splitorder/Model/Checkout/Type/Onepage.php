<?php

/**
 * Overwrite core
 */
class Scent_Splitorder_Model_Checkout_Type_Onepage extends Mage_Checkout_Model_Type_Onepage {

    protected $_oriAddresses = array();

    /**
     * Prepare order from quote_items  
     *
     * @param   array of Mage_Sales_Model_Quote_Item 
     * @return  Mage_Sales_Model_Order
     * @throws  Mage_Checkout_Exception
     */
    protected function _prepareOrder2($quoteItems) {
        $quote = $this->getQuote();
        $quote->unsReservedOrderId();
        $quote->reserveOrderId();

        // new instance of quote address
        $quote->setIsMultiShipping(true); // required for new instance of Mage_Sales_Model_Quote_Address
        $address = Mage::getModel('sales/quote_address');
        $weight = 0;
        $addressType = 'billing';
        foreach ($quoteItems as $quoteItem) {
            $address->addItem($quoteItem, $quoteItem->getQty());
            $weight += $quoteItem->getWeight();
            if (!$quoteItem->getIsVirtual()) {
                $addressType = 'shipping';
            }
        }
        // get original shipping address that contains multiple quote_items
        if (!isset($this->_oriAddresses[$addressType])) {
            $this->_oriAddresses[$addressType] = Mage::getResourceModel('sales/quote_address_collection')
                    ->setQuoteFilter($quote->getId())
                    ->addFieldToFilter('address_type', $addressType)
                    ->getFirstItem();
        }
        Mage::helper('core')->copyFieldset('sales_convert_quote_address', 'to_customer_address', $this->_oriAddresses[$addressType], $address);
        Mage::helper('core')->copyFieldset('sales_convert_quote_address', 'to_order', $this->_oriAddresses[$addressType], $address);
        $address->setQuote($quote)
                ->setWeight($weight)
                ->setSubtotal(0)
                ->setBaseSubtotal(0)
                ->setGrandTotal(0)
                ->setBaseGrandTotal(0)
                ->setCollectShippingRates(true)
                ->collectTotals()
                ->collectShippingRates()
        ;

        $convertQuote = Mage::getSingleton('sales/convert_quote');
        $order = $convertQuote->addressToOrder($address);
        $order->setBillingAddress(
                $convertQuote->addressToOrderAddress($quote->getBillingAddress())
        );

        if ($address->getAddressType() == 'billing') {
            $order->setIsVirtual(1);
        } else {
            $order->setShippingAddress($convertQuote->addressToOrderAddress($address));
        }

        $order->setPayment($convertQuote->paymentToOrderPayment($quote->getPayment()));
        if (Mage::app()->getStore()->roundPrice($address->getGrandTotal()) == 0) {
            $order->getPayment()->setMethod('free');
        }

        foreach ($quoteItems as $quoteItem) {
            $orderItem = $convertQuote->itemToOrderItem($quoteItem);  // use quote_item to transfer is_qty_decimal
            if ($quoteItem->getParentItem()) {
                $orderItem->setParentItem($order->getItemByQuoteItemId($quoteItem->getParentItem()->getId()));
            }
            $order->addItem($orderItem);
        }

        return $order;
    }

    /**
     * Overwrite core function
     */
    public function saveOrder() {
        $quote = $this->getQuote();
        if ($quote->getItemsCount() > 1) {
            $items = $quote->getAllVisibleItems();
            $group = array();
            $split = array();
            foreach ($items as $item) {
                $product_warehouse = Mage::getModel('catalog/product')->load($item->getProductId())->getWarehouse();

                $split[$product_warehouse][] = $item;
                $group[] = $item; // all other items in one order
            }
            if (count($split)) {
                if (count($group)) {
                    array_unshift($split, $group);
                }
                return $this->_splitQuote($split);
            }
        }
        return parent::saveOrder();
    }

    /**
     * Split quote to multiple orders
     * 
     * @param array of Mage_Sales_Model_Quote_Item
     * @return Mage_Checkout_Model_Type_Onepage
     */
    protected function _splitQuote($split) {
        $this->validate();
        $isNewCustomer = false;
        switch ($this->getCheckoutMethod()) {
            case self::METHOD_GUEST:
                $this->_prepareGuestQuote();
                break;
            case self::METHOD_REGISTER:
                $this->_prepareNewCustomerQuote();
                $isNewCustomer = true;
                break;
            default:
                $this->_prepareCustomerQuote();
                break;
        }
        if ($isNewCustomer) {
            try {
                $this->_involveNewCustomer();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        $quote = $this->getQuote()->save();
        $orderIds = array();
        Mage::getSingleton('core/session')->unsOrderIds();
        $this->_checkoutSession->clearHelperData();

        /**
         * a flag to set that there will be redirect to third party after confirmation
         * eg: paypal standard ipn
         */
        $redirectUrl = $quote->getPayment()->getOrderPlaceRedirectUrl();
        $ctr = 1;
        $parent_order_id = NULL;
        foreach ($split as $quoteItems) {
            $order = $this->_prepareOrder2($quoteItems);
            $order->place();
            if ($ctr != 1) {
                $order->setSplitOrderParentId($parent_order_id);
            }

            $order->save();
            if ($ctr == 1) {
                $parent_order_id = $order->getIncrementId();
            }
            Mage::dispatchEvent('checkout_type_onepage_save_order_after', array('order' => $order, 'quote' => $quote));
            /**
             * we only want to send to customer about new order when there is no redirect to third party
             */
            if (!$redirectUrl && $order->getCanSendNewEmailFlag()) {
                $order->sendNewOrderEmail();
            }
            $orderIds[$order->getId()] = $order->getIncrementId();
            if ($ctr == 1) {
                $parent_order_data["quote"] = $quote;
                $parent_order_data["order"] = $order;
                $ctr++;
            }
        }
        $quote = $parent_order_data["quote"];
        $order = $parent_order_data["order"];
        Mage::getSingleton('core/session')->setOrderIds($orderIds);

        // add order information to the session
        $this->_checkoutSession
                ->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getId())
                ->setRedirectUrl($redirectUrl)
                ->setLastRealOrderId($order->getIncrementId());

        // as well a billing agreement can be created
        $agreement = $order->getPayment()->getBillingAgreement();
        if ($agreement) {
            $this->_checkoutSession->setLastBillingAgreementId($agreement->getId());
        }

        // add recurring profiles information to the session
        $service = Mage::getModel('sales/service_quote', $quote);
        $profiles = $service->getRecurringPaymentProfiles();
        if ($profiles) {
            $ids = array();
            foreach ($profiles as $profile) {
                $ids[] = $profile->getId();
            }
            $this->_checkoutSession->setLastRecurringProfileIds($ids);
            // TODO: send recurring profile emails
        }

        Mage::dispatchEvent(
                'checkout_submit_all_after', array('order' => $order, 'quote' => $quote, 'recurring_profiles' => $profiles)
        );

        return $this;
    }

}
