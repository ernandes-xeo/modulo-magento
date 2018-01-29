<?php
/**
 * PHP version 5
 * Novapc Integracommerce
 *
 * @category  Magento
 * @package   Novapc_Integracommerce
 * @author    Novapc <novapc@novapc.com.br>
 * @copyright 2017 Integracommerce
 * @license   https://opensource.org/licenses/osl-3.0.php PHP License 3.0
 * @version   GIT: 1.0
 * @link      https://github.com/integracommerce/modulo-magento
 */

class Novapc_Integracommerce_Helper_OrderData extends Novapc_Integracommerce_Helper_Data
{
    public static function startOrders($requested, $orderModel)
    {
        /*CARREGANDO A QUANTIDADE DE REQUISICOES POR TEMPO*/
        $requestedHour = $orderModel->getRequestedHour();

        /*SOMA A QUANTIDADE ANTERIOR COM A RETORNADA*/
        $requestedHour = $requestedHour + $requested['Total'];
        $requestTime = Novapc_Integracommerce_Helper_Data::currentDate(null, 'string');

        /*GRAVA O HORARIO DA REQUISICAO E AS QUANTIDADES*/
        $orderModel->setStatus($requestTime);
        $orderModel->setRequestedHour($requestedHour);

        $orderModel->save();

        foreach ($requested['Orders'] as $order) {
            self::processingOrder($order);
        }
    }

    public static function processingOrder($order)
    {
        /*VERIFICA SE CLIENTE JÁ EXISTE*/
        if ($order['CustomerPfCpf']) {
            $customerDoc = $order['CustomerPfCpf'];
        } elseif ($order['CustomerPjCnpj']) {
            $customerDoc = $order['CustomerPjCnpj'];
        }

        if (!empty($customerDoc)) {
            $store = Mage::helper('integracommerce/data')->getStore();
            $customerCollection = Mage::getModel('customer/customer')
                ->getCollection()
                ->addFieldToFilter('taxvat', $customerDoc)
                ->addAttributeToSelect('*');

            foreach ($customerCollection as $customerData) {
                if ($customerData->isInStore($store) == true) {
                    $customerId = $customerData->getId();
                    $customer = $customerData;
                    break;
                }
            }
        }

        /*SE CLIENTE JA ESXISTE, ATUALIZA, SE NAO, CRIA*/
        if (!empty($customerId)) {
            self::updateCustomer($customer, $order);
        } else {
            $customerId = self::createCustomer($order);
        }

        /*VERIFIFA SE JA EXISTE PEDIDO NO MAGENTO COM O ID DA COMPRA INTEGRACOMMERCE*/
        $existingOrder = Mage::getModel('sales/order')->load($order['IdOrder'], 'integracommerce_id');

        $incrementId = $existingOrder->getIncrementId();
        if (!empty($incrementId)) {
            return;
        } else {
            /*CRIA O PEDIDO NA TABELA DE CONTROLE DO MODULO*/
            $integraModel = self::integraOrder($order, $customerId, null);
            /*CRIA O PEDIDO NO MAGENTO*/
            self::createOrder($order, $customerId, $integraModel);
        }
    }

    public static function createCustomer($order)
    {
        $ieAttribute = Mage::getStoreConfig('integracommerce/attributes/ierg', Mage::app()->getStore());
        $store = Novapc_Integracommerce_Helper_Data::getStore();
        $customer = Mage::getModel("customer/customer");
        $customer->setWebsiteId($store->getWebsiteId());
        $customer->setStore($store);
     
        $customer->setFirstname(
            (empty($order['CustomerPfName']) ? $order['CustomerPjCorporatename'] : $order['CustomerPfName'])
        );

        $customer->setLastname('.');
        $customer->setData(
            'taxvat',
            (empty($order['CustomerPfCpf']) ? $order['CustomerPjCnpj'] : $order['CustomerPfCpf'])
        );

        if (!empty($order['CustomerPjIe']) && $ieAttribute !== 'not_selected') {
            $customer->setData($ieAttribute, $customer['CustomerPjIe']);
        }

        $marketplaceName = strtolower($order['MarketplaceName']);
        $marketplaceName = preg_replace('/\s+/', '', $marketplaceName);
        $newEmail = $marketplaceName . '_' . mt_rand() . '@email.com.br';
        $customer->setEmail($newEmail);

        try {
            $customer->save();
        } catch (Exception $e) {
            $message = $e->getMessage();
            Mage::log('Erro ao criar o cliente. Mensagem: ' . $message, null, 'cust_save_error_integracommerce.log');
        }        

        //VERIFICA SE TEM DADOS PARA CADASTRAR ENDEREÇO  
        $region = Mage::getModel('directory/region')->loadByCode($order['DeliveryAddressState'], 'BR');

        $address = Mage::getModel("customer/address");
        $address->setCustomerId($customer->getId())
            ->setFirstname(
                (empty($order['CustomerPfName']) ? $order['CustomerPjCorporatename'] : $order['CustomerPfName'])
            )
            ->setLastname('.')           
            ->setCountryId('BR')
            ->setPostcode($order['DeliveryAddressZipcode'])
            ->setCity($order['DeliveryAddressCity'])
            ->setRegion($region->getName())
            ->setRegionId($region->getId())
            ->setTelephone($order['TelephoneMainNumber'])
            ->setFax($order['TelephoneSecundaryNumber'])
            ->setStreet(
                array(
                    $order['DeliveryAddressStreet'],
                    $order['DeliveryAddressNumber'],
                    (empty($order['DeliveryAddressAdditionalInfo']) ? '' : $order['DeliveryAddressAdditionalInfo']) ,
                    $order['DeliveryAddressNeighborhood']
                )
            )
            ->setIsDefaultBilling('1')
            ->setIsDefaultShipping('1')
            ->setSaveInAddressBook('1');

        try {
            $address->save(); 
        } catch (Exception $e){
            $message = $e->getMessage();
            Mage::log('Erro ao cadastrar endereço. Mensagem: '. $message, null, 'cust_save_error_integracommerce.log');
        }

        $customerId = $customer->getId();
        return $customerId;

    }
    
    public static function updateCustomer($customer,$order)
    {
        $defaultShippingId = $customer->getDefaultShipping();
        $address = Mage::getModel("customer/address");
        $address->load($defaultShippingId);

        $region = Mage::getModel('directory/region')->loadByCode($order['DeliveryAddressState'], 'BR');

        $address->setCustomerId($customer->getId())
            ->setFirstname(
                (empty($order['CustomerPfName']) ? $order['CustomerPjCorporatename'] : $order['CustomerPfName'])
            )
            ->setLastname('.')           
            ->setCountryId('BR')
            ->setPostcode($order['DeliveryAddressZipcode'])
            ->setCity($order['DeliveryAddressCity'])
            ->setRegion($region->getName())
            ->setRegionId($region->getId())
            ->setTelephone($order['TelephoneMainNumber'])
            ->setFax($order['TelephoneSecundaryNumber'])
            ->setStreet(
                array(
                    $order['DeliveryAddressStreet'],
                    $order['DeliveryAddressNumber'],
                    (empty($order['DeliveryAddressAdditionalInfo']) ? '' : $order['DeliveryAddressAdditionalInfo']),
                    $order['DeliveryAddressNeighborhood']
                )
            )
            ->setIsDefaultBilling('1')
            ->setIsDefaultShipping('1')
            ->setSaveInAddressBook('1');

        try {
            $address->save(); 
        } catch (Exception $e){
            $message = $e->getMessage();
            Mage::log('Erro ao cadastrar endereço. Mensagem: '.$message, null, 'cust_save_error_integracommerce.log');
        }        

        $customerId = $customer->getId();
        return $customerId;
    }

    public static function createOrder($order, $customerId, $integraModel = null)
    {
        $customer = Mage::getModel('customer/customer')->load($customerId);
        //INICIA O MODEL DE PEDIDO DO MAGENTO
        $transaction = Mage::getModel('core/resource_transaction');
        $storeId = $customer->getStoreId();

        if ($storeId == 0) {
            $storeId = 1;
        }

        //PEGA DO BANCO QUAL VAI SER O PROXIMO ID DE PEDIDO
        $reservedOrderId = Mage::getSingleton('eav/config')->getEntityType('order')->fetchNewIncrementId($storeId);

        $mageOrder = Mage::getModel('sales/order')
            ->setIncrementId($reservedOrderId)
            ->setStoreId($storeId)
            ->setQuoteId(0)
            ->setDiscountAmount(0)
            ->setShippingAmount(0)
            ->setShippingTaxAmount(0)
            ->setBaseDiscountAmount(0)
            ->setIsVirtual(0)
            ->setBaseShippingAmount(0)
            ->setBaseShippingTaxAmount(0)
            ->setBaseTaxAmount(0)
            ->setBaseToGlobalRate(1)
            ->setBaseToOrderRate(1)
            ->setStoreToBaseRate(1)
            ->setStoreToOrderRate(1)
            ->setTaxAmount(0)
            ->setGlobal_currency_code(Mage::app()->getBaseCurrencyCode())
            ->setBase_currency_code(Mage::app()->getBaseCurrencyCode())
            ->setStore_currency_code(Mage::app()->getBaseCurrencyCode())
            ->setOrder_currency_code(Mage::app()->getBaseCurrencyCode());

        $mageOrder->setCustomer_email($customer->getEmail())
            ->setCustomerFirstname($customer->getFirstname())
            ->setCustomerLastname($customer->getLastname())
            ->setCustomerTaxvat($customer->getTaxvat())
            ->setCustomerGroupId($customer->getGroupId())
            ->setCustomer_is_guest(0)
            ->setCustomer($customer);

        $billing = $customer->getDefaultBillingAddress();
        $billingAddress = Mage::getModel('sales/order_address')
                ->setStoreId($storeId)
                ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_BILLING)
                ->setCustomerId($customer->getId())
                ->setCustomerAddressId($customer->getDefaultBilling())
                ->setCustomer_address_id($billing->getEntityId())
                ->setPrefix($billing->getPrefix())
                ->setFirstname($billing->getFirstname())
                ->setLastname($billing->getLastname())
                ->setStreet($billing->getStreet())
                ->setCity($billing->getCity())
                ->setCountry_id($billing->getCountryId())
                ->setRegion($billing->getRegion())
                ->setRegion_id($billing->getRegionId())
                ->setEmail($customer->getEmail())
                ->setPostcode($billing->getPostcode())
                ->setTelephone($billing->getTelephone())
                ->setFax($billing->getFax())
                ->setVatId($customer->getData('taxvat'));
        $mageOrder->setBillingAddress($billingAddress);

        $shipping = $customer->getDefaultShippingAddress();
        $shippingAddress = Mage::getModel('sales/order_address')
            ->setStoreId($storeId)
            ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_SHIPPING)
            ->setCustomerId($customer->getId())
            ->setCustomerAddressId($customer->getDefaultShipping())
            ->setCustomer_address_id($shipping->getEntityId())
            ->setPrefix($shipping->getPrefix())
            ->setFirstname($shipping->getFirstname())
            ->setLastname($shipping->getLastname())
            ->setStreet($shipping->getStreet())
            ->setCity($shipping->getCity())
            ->setCountry_id($shipping->getCountryId())
            ->setRegion($shipping->getRegion())
            ->setRegion_id($shipping->getRegionId())
            ->setEmail($customer->getEmail())
            ->setPostcode($shipping->getPostcode())
            ->setTelephone($shipping->getTelephone())
            ->setFax($shipping->getFax())
            ->setVatId($customer->getData('taxvat'));

        //INSERINDO METODO DE ENTREGA
        if (empty($order['TotalFreight'])) {
            $shippingprice = 0;
        } else {
            $shippingprice = $order['TotalFreight'];
            $shippingprice = str_replace(',', '.', $order['TotalFreight']);
        }

        $mageOrder->setShippingAddress($shippingAddress)
            ->setShipping_method('flatrate_flatrate')
            ->setShippingDescription(
                "Loja: ". $order['StoreName'] .
                "\n, Marketplace: ". $order['MarketplaceName'] .
                "\n, Frete: " . $order['ShippedCarrierName']
            )
            ->setShippingAmount($shippingprice)
            ->setBaseShippingAmount($shippingprice);

        $marketplaceName = $order['MarketplaceName'];
        switch ($marketplaceName) {
            case "Magazine Luiza":
                $paymentMethod = 'integracommerce_magazine';
                break;
            case "Mobly":
                $paymentMethod = 'integracommerce_mobly';
                break;
            case "B2W":
                $paymentMethod = 'integracommerce_b2w';
                break;
            case "Walmart":
                $paymentMethod = 'integracommerce_walmart';
                break;
            case "Dafiti":
                $paymentMethod = 'integracommerce_dafiti';
                break;
            case "Carrefour":
                $paymentMethod = 'integracommerce_carrefour';
                break;
            case "Cnova":
                $paymentMethod = 'integracommerce_cnova';
                break;
            case "CNova":
                $paymentMethod = 'integracommerce_cnova';
                break;
        }

        $orderPayment = Mage::getModel('sales/order_payment')
            ->setStoreId($storeId)
            ->setCustomerPaymentId(0)
            ->setMethod($paymentMethod)
            ->setPo_number(' – ')
            ->setIntegracommerceName($order['Payments'][0]['Name'])
            ->setIntegracommerceInstallments($order['Payments'][0]['Installments']);
        $mageOrder->setPayment($orderPayment);

        $weightAttribute = Mage::getStoreConfig('integracommerce/attributes/weight', Mage::app()->getStore());
        $productControl = Mage::getStoreConfig('integracommerce/general/sku_control', Mage::app()->getStore());
        $subTotal = 0;

        $productsIds = array();
        $productsData = array();
        foreach ($order['Products'] as $key => $product) {
            $skuId = $product['IdSku'];
            if (array_key_exists($skuId, $productsData)) {
                $newQty = $productsData[$skuId]['Quantity'] + $product['Quantity'];
                $productsData[$skuId]['Quantity'] = $newQty;
            } else {
                $productsIds[] = $skuId;
                $productsData[$skuId]['Price'] =  $product['Price'];
                $productsData[$skuId]['Quantity'] = $product['Quantity'];
                $productsData[$skuId]['Discount'] = $product['Discount'];
            }
        }

        $productCollection = Mage::getModel('catalog/product')->getCollection()
            ->addFieldToFilter($productControl, array('in' => $productsIds))
            ->addAttributeToSelect('*');

        foreach ($productCollection as $key => $mageProduct) {
            $skuId = $mageProduct->getData($productControl);
            $productId = $mageProduct->getId();
            $productsData[$skuId]['product_id'] = $productId;

            $newPrice = (float) str_replace(',', '.', $productsData[$skuId]['Price']);
            $discount = (float) str_replace(',', '.', $productsData[$skuId]['Discount']);

            if ($discount > 0) {
                $newPrice = $newPrice - $discount;
            }

            $rowTotal = $newPrice * $productsData[$skuId]['Quantity'];
            $orderItem = Mage::getModel('sales/order_item')
                ->setStoreId($storeId)
                ->setQuoteItemId(0)
                ->setQuoteParentItemId(null)
                ->setProductId($productId)
                ->setProductType($mageProduct->getTypeId())
                ->setQtyBackordered(null)
                ->setTotalQtyOrdered($productsData[$skuId]['Quantity'])
                ->setQtyOrdered($productsData[$skuId]['Quantity'])
                ->setName($mageProduct->getName())
                ->setSku($mageProduct->getSku())
                ->setPrice($newPrice)
                ->setWeight($mageProduct->getData($weightAttribute))
                ->setBasePrice($newPrice)
                ->setOriginalPrice($newPrice)
                ->setRowTotal($rowTotal)
                ->setBaseRowTotal($rowTotal);

            $subTotal += $rowTotal;
            $mageOrder->addItem($orderItem);
            Mage::getSingleton('cataloginventory/stock')->registerItemSale($orderItem);
        }

        $mageOrder->setSubtotal($subTotal)
            ->setBaseSubtotal($subTotal)
            ->setGrandTotal($subTotal + $shippingprice)
            ->setBaseGrandTotal($subTotal + $shippingprice);

        $mageOrder->setData('integracommerce_id', $order['IdOrder']);

        $estimatedDate = substr($order['EstimatedDeliveryDate'], 0, 10);
        $estimatedDate = DateTime::createFromFormat('Y-m-d', $estimatedDate);
        $estimatedDate = $estimatedDate->format('d/m/Y');
        
        $comment = $mageOrder->addStatusHistoryComment(
            "Código do Pedido Integracommerce: " .
            $order['IdOrder'] . "<br>" . "Código do Pedido Marketplace: " .
            $order['IdOrderMarketplace'] . "<br>" . "Data Estimada de Entrega: " .
            $estimatedDate,
            false
        );

        $comment->setIsCustomerNotified(false);

        try {
            $transaction->addObject($mageOrder);
            $transaction->addCommitCallback(array($mageOrder, 'place'));
            $transaction->addCommitCallback(array($mageOrder, 'save'));
            $transaction->save();
        } catch (Exception $e) {
            foreach ($productsData as $product) {
                Mage::getSingleton('cataloginventory/stock')
                    ->backItemQty($product['product_id'], $product['Quantity']);
            }

            $integraModel->setMageError($e->getMessage());
            $integraModel->save();
        }

        $entityId = $mageOrder->getEntityId();
        $updateIncrementId = $mageOrder->getIncrementId();
        
        self::afterCreate($updateIncrementId, $order, $entityId, $mageOrder, $integraModel);

        $integraModel->setMagentoOrderId($entityId);
        $integraModel->setMagentoCustomerId($customer->getId());
        $integraModel->setCustomerEmail($customer->getEmail());
        $integraModel->save();

        return $entityId;
    }

    public static function afterCreate($updateIncrementId, $order, $entityId, $mageOrder, $integraModel)
    {
        if (!empty($updateIncrementId)) {
            self::updateIntegraOrder($order['IdOrder'], $entityId);
            $status = Mage::getStoreConfig('integracommerce/order_status/approved', Mage::app()->getStore());
            if ($status !== 'keepstatus') {
                $states = array();
                $stateCollection = Mage::getModel('integracommerce/order')->getCollection()->orderStatusFilter($status);
                foreach ($stateCollection as $state) {
                    $states[] = $state->getState();
                }

                $mageOrder->setData('state', $states[0]);
                $mageOrder->setStatus($status);
                $history = $mageOrder->addStatusHistoryComment("Status no Integracommerce: Aprovado", false);
                $history->setIsCustomerNotified(false);

                try {
                    $mageOrder->save();
                } catch (Exception $e) {
                    $integraModel->setMageError($e->getMessage());
                    $integraModel->save();
                }
            }
        }

        $shouldInvoice = Mage::getStoreConfig('integracommerce/order_status/invoice', Mage::app()->getStore());
        if ($mageOrder->canInvoice() && $shouldInvoice == 1) {
            self::createInvoice($mageOrder);
        }
    }

    public static function createInvoice($mageOrder)
    {
        $invoice = Mage::getModel('sales/service_order', $mageOrder)->prepareInvoice();
        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->getOrder()->setCustomerNoteNotify(false);
        $invoice->getOrder()->setIsInProcess(true);
        $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID);

        $transactionSave = Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder());
        $transactionSave->save();
    }

    public static function updateIntegraOrder($orderId, $mageOrderId)
    {
        $environment = Mage::getStoreConfig('integracommerce/general/environment', Mage::app()->getStore());
        $url = 'https://' . $environment . '.integracommerce.com.br/api/Order';

        $body = array(
            "IdOrder" => $orderId,
            "OrderStatus" => 'PROCESSING'
        );

        $jsonBody = json_encode($body);

        $return = Novapc_Integracommerce_Helper_Data::callCurl("PUT", $url, $jsonBody);

        if ($return['httpCode'] !== 204) {
            if (!empty($return['Errors'])) {
                foreach ($return['Errors'] as $error) {
                    $errorMessage = $error['Message'] . ', ';
                }

                Mage::log(
                    'Error: ' . $return['httpCode'] .
                    'Erro ao atualizar o pedido ' . $mageOrderId .
                    ', Codigo Integracommerce: ' . $orderId .
                    '. Motivo: ' . $return['Message'] .
                    '. Erros: ' . $errorMessage, null, 'integracommerce_order_update_error.log'
                );
            }

            $requestLog = Mage::getStoreConfig('integracommerce/general/request_log', Mage::app()->getStore());
            if ($requestLog == 1) {
                Mage::log('Requisição: ' . $jsonBody, null, 'integracommerce_order_request.log');
            }
        }
    }

    public static function integraOrder($order,$customerId,$mageOrder = null)
    {
        $customer = Mage::getModel('customer/customer')->load($customerId);
        $integraOrder = Mage::getModel('integracommerce/order')->load($order['IdOrder'], 'integra_id');

        $integraId = $integraOrder->getIntegraId();
        if (empty($integraId)) {
            $integraOrder = Mage::getModel('integracommerce/order');
            $integraOrder->setIntegraId($order['IdOrder']);
        }

        $integraOrder->setMarketplaceId($order['IdOrderMarketplace']);
        $integraOrder->setMarketplaceName($order['MarketplaceName']);
        $integraOrder->setStoreName($order['StoreName']);

        $integraOrder->setCustomerPfCpf((empty($order['CustomerPfCpf']) ? "" : $order['CustomerPfCpf']));
        $integraOrder->setCustomerPfName((empty($order['CustomerPfName']) ? "" : $order['CustomerPfName']));
        $integraOrder->setCustomerPjCnpj((empty($order['CustomerPjCnpj']) ? "" : $order['CustomerPjCnpj']));
        $integraOrder->setCustomerPjCorporateName(
            (empty($order['CustomerPjCorporatename']) ? "" : $order['CustomerPjCorporatename'])
        );

        $integraOrder->setDeliveryStreet($order['DeliveryAddressStreet']);
        $integraOrder->setDeliveryAdditionalInfo($order['DeliveryAddressAdditionalInfo']);
        $integraOrder->setDeliveryNeighborhood($order['DeliveryAddressNeighborhood']);
        $integraOrder->setDeliveryCity($order['DeliveryAddressCity']);
        $integraOrder->setDeliveryReference($order['DeliveryAddressReference']);
        $integraOrder->setDeliveryState($order['DeliveryAddressState']);
        $integraOrder->setDeliveryNumber($order['DeliveryAddressNumber']);
        $integraOrder->setTelephoneMain($order['TelephoneMainNumber']);
        $integraOrder->setTelephoneSecondary($order['TelephoneSecundaryNumber']);
        $integraOrder->setTelephoneBusiness($order['TelephoneBusinessNumber']);
        $integraOrder->setTotalAmount($order['TotalAmount']);
        $integraOrder->setTotalFreight($order['TotalFreight']);
        $integraOrder->setTotalDiscount($order['TotalDiscount']);
        $integraOrder->setOrderStatus($order['OrderStatus']);

        if (isset($mageOrder)) {
            $integraOrder->setMagentoOrderId($mageOrder);
            $integraOrder->setMagentoCustomerId($customer->getId());
            $integraOrder->setCustomerEmail($customer->getEmail());
        }

        $integraOrder->setInsertedAt($order['InsertedDate']);
        $integraOrder->setPurchasedAt($order['PurchasedDate']);
        $integraOrder->setApprovedAt($order['ApprovedDate']);
        $integraOrder->setUpdatedAt($order['UpdatedDate']);

        try {
            $integraOrder->save();
        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, 'integra_order_save_error_integracommerce.log');
        }

        return $integraOrder;
    }

    public static function checkDate($line, $integraModel)
    {
        if (!empty($line)) {
            $ymd = DateTime::createFromFormat('d/m/Y', $line);
            if ($ymd) {
                $line = $ymd->format('Y-m-d\TH:i:s\.000-03:00');
            } else {
                $ymd = DateTime::createFromFormat('d/m/Y H:i:s', $line);
                if ($ymd) {
                    $line = $ymd->format('Y-m-d\TH:i:s\.000-03:00');
                } else {
                    $message = 'Motivo: Data inválida. Erros: a data deve seguir o padrão brasileiro';
                    $integraModel->setMageError($message);
                    $integraModel->save();
                    return;
                }
            }

            return $line;
        }
    }

    public static function nfeUpdate($commentData, $createdAt, $integraModel, $line, $orderId)
    {
        $formatoNfe = Mage::getStoreConfig('integracommerce/order_status/nfe_model', Mage::app()->getStore());
        $messageNF = "Não foi possivel enviar os dados da Nota Fiscal. Informações inválidas.";

        if (count($line) < 4 && $formatoNfe == 'old') {
            return Mage::throwException($messageNF);
        }

        //VERIFICANDO FORMATO UTILIZADO
        if ($formatoNfe == 'new') {
            $preparedNfe = self::nfeFormat($commentData, $createdAt);

            if (empty($preparedNfe)) {
                return Mage::throwException($messageNF);
            }

            $line[0] = $preparedNfe['numeroNota'];
            $line[1] = $preparedNfe['serieNota'];

            $return = self::checkDate($preparedNfe['dataEmissaoNota'], $integraModel);

            $line[2] = $return;
            if (strlen($preparedNfe['chaveNota']) < 44) {
                $trimData = trim($preparedNfe['chaveNota'], " ");
                $line[3] = str_pad($trimData, 44, "0");
            } else {
                $trimData = trim($preparedNfe['chaveNota'], " ");
                $line[3] = $trimData;
            }

            $line[4] = $preparedNfe['xmlNota'];
        } else {
            //CHECANDO DATA DE EMISSAO DA FATURA
            $return = self::checkDate($line[2], $integraModel);
            $line[2] = $return;

            if (strlen($line[3]) < 44) {
                $trimData = trim($line[3], " ");
                $line[3] = str_pad($trimData, 44, "0");
            } else {
                $trimData = trim($line[3], " ");
            }
        }

        $body = array(
            "IdOrder" => $orderId,
            "OrderStatus" => "INVOICED",
            "InvoicedNumber" => $line[0],
            "InvoicedLine" => $line[1],
            "InvoicedIssueDate" => $line[2],
            "InvoicedKey" => $line[3],
            "InvoicedDanfeXml" => (empty($line[4]) ? "" : $line[4])
        );

        return $body;
    }

    public static function shipUpdate($integraModel, $line, $orderId)
    {
        if (count($line) !== 5) {
            $message = "Não foi possivel enviar os dados de Rastreio. Informações inválidas.";
            return Mage::throwException($message);
        }

        //CHECANDO DATA ESTIMADA DE ENTREGA
        $return = self::checkDate($line[2], $integraModel);
        $line[2] = $return;

        //CHECANDO DATA DE ENTREGA A TRANSPORTADORA
        $return = self::checkDate($line[3], $integraModel);
        $line[3] = $return;

        $body = array(
            "IdOrder" => $orderId,
            "OrderStatus" =>"SHIPPED",
            "ShippedTrackingUrl" => (empty($line[0]) ? "" : $line[0]),
            "ShippedTrackingProtocol" => (empty($line[1]) ? "" : $line[1]),
            "ShippedEstimatedDelivery" => $line[2],
            "ShippedCarrierDate" => $line[3],
            "ShippedCarrierName" => $line[4]
        );

        return $body;
    }

    public static function delivUpdate($commentData, $integraModel, $orderId)
    {
        //CHECANDO DATA ESTIMADA DE ENTREGA
        $return = self::checkDate($commentData, $integraModel);
        $deliveredDate = $return;

        $body = array(
            "IdOrder" => $orderId,
            "OrderStatus" => "DELIVERED",
            "DeliveredDate" => $deliveredDate
        );

        return $body;
    }

    public static function failUpdate($integraModel, $line, $orderId)
    {
        if (count($line) !== 2) {
            $message = "Não foi possivel enviar os dados de Falha no Envio. Informações inválidas.";
            return Mage::throwException($message);
        }

        $return = self::checkDate($line[1], $integraModel);
        $line[1] = $return;

        $body = array(
            "IdOrder" => $orderId,
            "OrderStatus" => "SHIPMENT_EXCEPTION",
            "ShipmentExceptionObservation" => $line[0],
            "ShipmentExceptionOccurrenceDate" => $line[1]
        );

        return $body;
    }

    public static function startUpdate($body, $integraModel)
    {
        $environment = Mage::getStoreConfig('integracommerce/general/environment', Mage::app()->getStore());
        $url = 'https://' . $environment . '.integracommerce.com.br/api/Order';
        $jsonBody = json_encode($body);
        $return = Novapc_Integracommerce_Helper_Data::callCurl("PUT", $url, $jsonBody);

        if ($return['httpCode'] !== 204) {
            if (!empty($return['Errors'])) {
                foreach ($return['Errors'] as $error) {
                    $return = $error['Message'] . '. ';
                };
            } elseif ($return['httpCode'] == 200) {
                $return = 'Dados inseridos';
                $integraModel->setOrderStatus($body['OrderStatus']);
                $integraModel->save();
            } else {
                $return = json_encode($return);
            }

            $integraModel->setIntegraError($return);
            $integraModel->save();
        } else {
            $integraModel->setOrderStatus($body['OrderStatus']);
            $integraModel->save();
        }
    }

    public static function updateOrder($order, $comment)
    {
        $shippingStatus = Mage::getStoreConfig('integracommerce/order_status/dados_rastreio', Mage::app()->getStore());
        $invoiceStatus = Mage::getStoreConfig('integracommerce/order_status/nota_fiscal', Mage::app()->getStore());
        $integraModel = Mage::getModel('integracommerce/order')
            ->load($order->getData('integracommerce_id'), 'integra_id');
        $orderId = $order->getData('integracommerce_id');

        try {
            $status = $comment->getStatus();
            $commentData = $comment->getComment();
            
            $lines = explode('|', $commentData);
            if ((empty($lines) && $status !== 'delivered') || empty($commentData)) {
                return;
            }

            $line = array();
            foreach ($lines as $_line) {
                $line[] = $_line;
            }

            if (($invoiceStatus && !empty($invoiceStatus)) && $invoiceStatus == $status) {
                $createdAt = $comment->getCreatedAt();
                $timeZone = Mage::getStoreConfig('general/locale/timezone');
                $lastRequest = new DateTime($createdAt, new DateTimeZone($timeZone));
                $createdAt = $lastRequest->format('d/m/Y');
                $body = self::nfeUpdate($commentData, $createdAt, $integraModel, $line, $orderId);
            } elseif (($shippingStatus && !empty($shippingStatus)) && $shippingStatus == $status) {
                $body = self::shipUpdate($integraModel, $line, $orderId);
            } elseif ($status == 'delivered') {
                $body = self::delivUpdate($commentData, $integraModel, $orderId);
            } elseif ($status == 'shipexception') {
                $body = self::failUpdate($integraModel, $line, $orderId);
            }

            if (isset($body)) {
                $actual = $integraModel->getOrderStatus();
                if ($status == $actual) {
                    return;
                }

                self::startUpdate($body, $integraModel);
            }
        } catch (Exception $e) {
            $integraModel->setIntegraError($e->getMessage());
            $integraModel->save();
        }
    }

    public static function viewOrder($id)
    {
        $integraOrder = Mage::getModel('integracommerce/order')->load($id, 'integra_id');
        $mageCustomer = Mage::getModel('customer/customer')->load($integraOrder->getMagentoCustomerId());
        $mageOrder = Mage::getModel('sales/order')->load($integraOrder->getMagentoOrderId());

        return array($integraOrder,$mageCustomer,$mageOrder);
    }

    public static function nfeFormat($commentData, $createdAt = null)
    {
        preg_match('/^Nota/', $commentData, $checkData, PREG_OFFSET_CAPTURE);
        if (strpos($commentData, 'Nota') === false) {
            return;
        }

        $useCommentDate = Mage::getStoreConfig('integracommerce/order_status/invoice_date', Mage::app()->getStore());
        $preparedArray = array();
        if($commentData != strip_tags($commentData)) {
            $search = array("<br>", "<br />", "<br/>");
            $commentData = str_replace($search, " #n ", $commentData);

            $commentData = strip_tags($commentData, "<p><h1><h2><h3><h4><h5><h6></p></h1></h2></h3></h4></h5></h6>");

            $commentData = str_replace(" #n ", " \\n ", $commentData);
        }

        $commentArray = explode("\\n", $commentData);
        if (!is_array($commentArray) || count($commentArray) < 4) {
            $commentArray = explode("\n", $commentData);
        }

        $preparedArray['numeroNota'] = substr(trim($commentArray[0]), 13);
        $preparedArray['serieNota'] = substr(trim($commentArray[1]), 7);

        if ($useCommentDate == true && !empty($createdAt)) {
            $preparedArray['dataEmissaoNota'] = $createdAt;
            if (count($commentArray) < 5) {
                array_splice($commentArray, 2, 0, $createdAt);
            }
        } else {
            $preparedArray['dataEmissaoNota'] = substr(trim($commentArray[2]), 18);
        }

        $chaveNota = substr(trim($commentArray[3]), 15);
        $preparedArray['chaveNota'] = trim(str_replace(":", "", $chaveNota));

        if (empty($preparedArray['chaveNota']) || preg_match("/[a-z]/i", $preparedArray['chaveNota'])) {
            $preparedArray['chaveNota'] = substr(trim($commentArray[4]), 15);
        }

        if (isset($commentArray[5])) {
            $preparedArray['xmlNota'] = substr(trim($commentArray[5]), 15);
            if (empty($preparedArray['xmlNota'])) {
                $preparedArray['xmlNota'] = substr(trim($commentArray[5]), 13);
            }
        } else {
            $preparedArray['xmlNota'] = "";
        }

        return $preparedArray;
    }

    public static function getHistoryByStatus($order, $statusId)
    {
        foreach ($order->getStatusHistoryCollection(true) as $status) {
            if ($status->getStatus() == $statusId) {
                return $status;
            }
        }

        return false;
    }
}