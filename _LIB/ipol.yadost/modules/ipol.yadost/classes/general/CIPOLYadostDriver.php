<?
IncludeModuleLangFile(__FILE__);

class CIPOLYadostDriver
{
	const CACHE_TIME = 86400;// ����� ������ ���
	private static $agentCall = false;
	
	/////////////////////////////////////////////////////////////////////////////
	// ����� ���������� ��������
	/////////////////////////////////////////////////////////////////////////////
	static $MODULE_ID = 'ipol.yadost';
	static $tmpDeliveryStatus;// ������� ������ �� ������
	static $formData;
	
	// ��������� ������� �������
	// $arStatus = array(bitrixOrderID => ydStatus);
	static $assessedCostPercent = null;
	
	// ������� ����������� �������
	static $tmpOrder;
	
	// ������� ������� � �������
	static $tmpOrderProps;
	
	// ������� �������, ������� ������ �������������
	static $tmpOrderConfirm;
	
	/////////////////////////////////////////////////////////////////////////////
	// ������ �������� �� ajax �������� ������
	/////////////////////////////////////////////////////////////////////////////
	
	// �������� ������ � �������
	/*
	$params = array(
			ORDER_ID
			perform_actions = "confirm" ����������� �����, false - ������� ��������
			delivery_type = import - ���������� ������� , withdraw - �����
			import_type = courier � ����� ������; , car � ������������� ������.
		)
	*/
	static $tmpOrderBasket;
	static $tmpOrderDimension;
	static $tmpOrderID = null;
	static $zeroWeightGoods = array();
	
	// ��������� 
	static $zeroGabsGoods = array();
	
	// ��������� ���������� � ������� ������
	static $totalWeightMoreDefault;
	
	// ������� ������ ������
	static $options;
	
	/////////////////////////////////////////////////////////////////////////////
	// ���������� ��������
	/////////////////////////////////////////////////////////////////////////////
	
	// ��������� ���������� ������
	static $requestSend = false;
	
	// ��������� ������� ������
	static $requestConfig = false;
	
	/*
	������� ���� �����-���� �� ��
		$input = array(
			"bitrix_ID" - �� ������ � �������
			"delivery_ID" - �� ������ � ������
		)
	*/
	// ��������� ������� ������
	static $debug = array();
	
	// ���������������, ��������� ���� �� ������ � ����� � ������ ��, ���� ���, ����� �� ������� ������
	static $configFileName = false;
	
	// �������� ������ � ��������� ������ ��������
	
	static public function agentOrderStates()
	{
		$returnVal = 'CIPOLYadostDriver::agentOrderStates();';
		
		if (!CModule::IncludeModule("sale"))
			return $returnVal;
		
		// ������� ����������� �������
		$arEndStatus = self::getEndStatus();
		
		// �������� ����������� ������ �� � ��������� ������� �� ��������� 2 ������
		$dbOrders = CIPOLYadostSqlOrders::getList(array(
			"filter" => array(
				"!STATUS" => $arEndStatus,
				"!delivery_ID" => false
			)
		));
		
		// �������� ������ �� � ��������� ��������
		$deliveryIDs = array();// �� ������� � ������
		$arOrders = array();// �� ������� � �������
		$deliveryStatus = array();// ������� ������� � ��
		
		while ($arOrder = $dbOrders->Fetch())
		{
			$deliveryStatus[$arOrder["ORDER_ID"]] = $arOrder["STATUS"];
			$arOrders[$arOrder["ORDER_ID"]] = $arOrder["ORDER_ID"];
			$deliveryIDs[$arOrder["delivery_ID"]] = $arOrder["ORDER_ID"];
		}
		
		self::$tmpDeliveryStatus = $deliveryStatus;
		
		//== ��� �� ��������� ����� ������� �� ������, �� ���� ������, ����� �� ������ �����������
		$arStatus = array();
		$arTracks = array();// ���� ������ �������
		self::$agentCall = true;
		foreach ($deliveryIDs as $deliveryID => $bitrixID)
		{
			$status = self::getOrderStatus(array("delivery_ID" => $deliveryID));
			
			if (is_array($status))// ������ ������ ������, �� ���� ����������� ���� ����� �� ��
				unset($arOrders[$bitrixID]);
			else
			{
				$arStatus[$bitrixID] = $status;
				$arTracks[$bitrixID] = $bitrixID . "-YD" . $deliveryID;
				/*try
				{
					$res = self::getOrderInfo($bitrixID);
					$success = true;
				}
				catch(Exception $e)
				{
					$res = array();
					$success = false;
				}
				
				if ($success)
				{
					$bxID = $res["OrderInfo"]["data"]["num"];
					$ydID = $res["OrderInfo"]["data"]["id"];
					
					if ($bxID && $ydID)
						$arTracks[$bitrixID] = $bxID . "-YD" . $ydID;
				}*/
			}
		}
		self::$agentCall = false;
		
		// ���������������� ���������� ��������
		self::updateOrderStatus($arStatus);
		// ���������������� ���������� ���� �������
		self::updateTrackNumbers($arTracks);
		
		return $returnVal;
	}
	
	// ��������� ������ ������, ������������ ��� �������
	
	static public function updateOrderStatus($arStatus)
	{
		if (!CModule::IncludeModule("sale"))
			return false;
		
		// �������� ������� ���������� ������� � �������
		$arOrders = array_keys($arStatus);
		self::getModuleSetups();
		
		$dbOrders = CSaleOrder::GetList(
			array(),
			array("ID" => $arOrders),
			false,
			false,
			array("ID", "STATUS_ID")
		);
		
		$statusOrders = array();
		while ($arOrder = $dbOrders->Fetch())
			$statusOrders[$arOrder["ID"]] = $arOrder["STATUS_ID"];
		
		global $USER;
		$userCreated = false;
		if (!is_object($USER))
		{
			$userCreated = true;
			$USER = new CUser();
		}
		
		self::getModuleSetups();
		
		// ��������� ������� ��� ��������� �������
		foreach ($arStatus as $orderID => $ydStatus)
		{
			// ��������� ������ ������ � �������
			$updateSqlStatus = false;
			if (self::$tmpDeliveryStatus[$orderID])
			{
				if (self::$tmpDeliveryStatus[$orderID] != $ydStatus)
					$updateSqlStatus = true;
			}
			else
				$updateSqlStatus = true;
			
			if ($updateSqlStatus)
				CIPOLYadostSqlOrders::updateCustom(
					array("ORDER_ID" => $orderID),
					array("STATUS" => $ydStatus)
				);
			
			// ���� ������ ������ � �������, �� ���� �������� ��������� ������
			$errorStatus = self::getErrorStatus();
			
			if (in_array($ydStatus, $errorStatus))
			{
				$change = array(
					"event" => "ERROR_STATUS_ORDER",
					"orderID" => $orderID
				);
				
				CIPOLYadostHelper::updateNoticeFileData($change);
			}
			
			
			if (!empty(self::$options["STATUS"]))
			{
				// ���� ������� ������ ���������� �� �����������, �� ������ ���
				if ($ydStatus == "NEW" || self::$options["STATUS"][$ydStatus])
					if ($statusOrders[$orderID] != self::$options["STATUS"][$ydStatus])
						CSaleOrder::StatusOrder($orderID, self::$options["STATUS"][$ydStatus]);
			}
		}
		
		if ($userCreated)
			unset($USER);
		
		return true;
	}
	
	static public function updateTrackNumbers($arTracks)
	{
		if (!CModule::IncludeModule("sale"))
			return false;
		
		global $USER;
		$userCreated = false;
		if (!is_object($USER))
		{
			$userCreated = true;
			$USER = new CUser();
		}
		
		foreach ($arTracks as $orderID => $trackNumber)
		{
			$arOrder = CSaleOrder::getByID($orderID);
			
			if (empty($arOrder["TRACKING_NUMBER"]))
				CSaleOrder::update($orderID, array(
					"TRACKING_NUMBER" => $trackNumber
				));
		}
		
		if ($userCreated)
			unset($USER);
		
		return true;
	}
	
	// ��������� ������ ��������, ������������ ��� �������
	
	static public function getEndStatus()
	{
		return array(
			"CANCELED", // �������
			"RETURN_RETURNED", // ��������� � �������
			"DELIVERY_DELIVERED", // ���������
			// "ERROR", // ������
		);
	}
	
	// ��������� ������ �����������, ������������ ��� �������
	
	static public function getErrorStatus()
	{
		return array(
			"ERROR", // ������
		);
	}
	
	// ������������� ������ � ������� ��� �� ���������
	
	static public function getNotEditableStatus()
	{
		return array(
			"CREATED", "SENDER_SENT", "DELIVERY_LOADED", "FULFILMENT_LOADED"
		);
	}
	
	// �������� �������� ������ � ������
	
	static public function saveFormData(&$params)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		$orderID = $params["ORDER_ID"];
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Order ID empty", $params);
		
		// ��������� ������ �����, �������
		if (!empty($params["data"]["widgetDataJSON"]) && !empty($params["data"]["formDataJSON"]))
		{
			$dbRes = CIPOLYadostSqlOrders::updateCustom(
				array(
					"ORDER_ID" => $orderID
				),
				array(
					"PARAMS" => CIPOLYadostHelper::convertFromUTF($params["data"]["widgetDataJSON"]),
					"MESSAGE" => CIPOLYadostHelper::convertFromUTF($params["data"]["formDataJSON"])
				)
			);
			
			if (!$dbRes)
				CIPOLYadostHelper::throwException("Cant update DB", array(CIPOLYadostSqlOrders::getErrorMessagesCustom(), $params));
		}
		
		// ���� �������� ����� ���������� ��������� �������� � ������
		if ("Y" == $params["data"]["formData"]["change_delivery_price"])
		{
			// ������������� ���������
			self::getOrder($orderID);
			$shift = floatVal($params["data"]["formData"]["delivery_price"]) - floatVal(self::$tmpOrder["PRICE_DELIVERY"]);
			
			self::$tmpOrder["PRICE_DELIVERY"] = floatVal(self::$tmpOrder["PRICE_DELIVERY"]) + $shift;
			self::$tmpOrder["PRICE"] = floatVal(self::$tmpOrder["PRICE"]) + $shift;
			
			if (CModule::IncludeModule("sale"))
			{
				$tmpOrderData = self::$tmpOrder;
				$tmpOrderProps = self::$tmpOrderProps;
				
				if (CIPOLYadostHelper::isConverted())
					$arFields = array(
						"PRICE_DELIVERY" => self::$tmpOrder["PRICE_DELIVERY"]
					);
				else
					$arFields = array(
						"PRICE_DELIVERY" => self::$tmpOrder["PRICE_DELIVERY"],
						"PRICE" => self::$tmpOrder["PRICE"]
					);
				
				CSaleOrder::Update($orderID, $arFields);
				
				self::$tmpOrder = $tmpOrderData;
				self::$tmpOrderProps = $tmpOrderProps;
			}
		}
		
		// ���� ���������, ������������� � �������� ������ ����� ��������
		if ("PICKUP" == $params["data"]["formData"]["profile_name"])
		{
			CIPOLYadostHelper::updateAddressProp($orderID, CIPOLYadostHelper::convertFromUTF($params["data"]["formData"]["address"]));
		}
		
		return array("saveFormData" => true);
	}
	
	// �������� � ��
	
	static public function sendOrder($params)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		$orderID = $params["ORDER_ID"];
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Order ID empty", $params);
		
		$arDeliveryTypes = array("import", "withdraw");
		if (!in_array($params["data"]["formData"]["delivery_type"], $arDeliveryTypes))
			CIPOLYadostHelper::throwException("Invalid delivery_type", $params);
		
		self::$formData = CIPOLYadostHelper::convertFromUTF($params["data"]["formData"]);
		self::$tmpOrderConfirm = CIPOLYadostHelper::convertFromUTF($params["data"]);
		
		self::saveFormData($params);
		
		unset($params["data"]["widgetDataJSON"]);
		unset($params["data"]["formDataJSON"]);
		
		$arResult = array();
		
		// ���������� �������� ��� ������ � ����� ������, ��� ��� ����� ��� ���� �������
		self::getModuleSetups();
		self::$options["to_yd_warehouse"] = $params["data"]["formData"]["to_yd_warehouse"];
		
		if (isset($params["data"]["formData"]["assessedCostPercent"]))
			self::$assessedCostPercent = FloatVal($params["data"]["formData"]["assessedCostPercent"]);
		if (self::$assessedCostPercent === null)
			self::$assessedCostPercent = FloatVal(COption::GetOptionString(CIPOLYadostDriver::$MODULE_ID, 'assessedCostPercent', '100'));
		
		$arResult["sendDraft"] = self::sendOrderDraft($orderID);
		
		if ($arResult["sendDraft"]["status"] == "ok")
		{
			self::$tmpOrderConfirm["savedParams"]["delivery_ID"] = $arResult["sendDraft"]["data"]["order"]["id"];
			self::updateOrderStatus(array($orderID => "DRAFT"));
		}
		
		// ���� ����������, �� ���������� ����� � ��
		if ($params["perform_actions"] == "confirm")
		{
			if (empty(self::$tmpOrderConfirm["savedParams"]["parcel_ID"]))
			{
				// self::$tmpOrderConfirm = false;
				// ����������� ���� � ������ �������
				$shipmentDate = self::convertDataFromAdmin($params["data"]["formData"]["shipment_date"]);
				
				$arResult["confirmOrder"] = self::confirmOrder($orderID, $params["data"]["formData"]["delivery_type"], $shipmentDate);// ������������ �����
			}
		}
		
		// ��������� ������ ������ ����� ���� ��������
		$deliveryID = self::$tmpOrderConfirm["savedParams"]["delivery_ID"];
		if ($arResult["sendDraft"])
			$deliveryID = $arResult["sendDraft"]["data"]["order"]["id"];
		
		$arResult["STATUS"] = self::getOrderStatus(array("delivery_ID" => $deliveryID));
		
		self::updateOrderStatus(array($orderID => $arResult["STATUS"]));
		
		return $arResult;
	}
	
	// ������ � ���� ��������� � ������
	
	static public function getOrderDocuments($params)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		self::delOrderDocuments(); //������� ������ �����
		
		$orderID = $params["ORDER_ID"];
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Order ID empty", $params);
		
		$filePath = $_SERVER['DOCUMENT_ROOT'] . "/upload/" . self::$MODULE_ID;
		
		$fileNames = array(
			"docs" => $filePath . "/" . self::getOrderDocsNumber("docs", $orderID) . ".pdf",
			"labels" => $filePath . "/" . self::getOrderDocsNumber("labels", $orderID) . ".pdf"
		);
		
		$getRequestDocs = false;
		foreach ($fileNames as $filename)
			if (!file_exists($filename))
				$getRequestDocs = true;
		
		if ($getRequestDocs)
		{
			$docsRes = self::getOrderDocs($orderID);
			$labelRes = self::getOrderLabels($orderID);
			
			$arDocs = array();
			$errors = array();
			if (!empty($docsRes["data"]["errors"]))
				$errors[] = $docsRes["data"]["errors"]["parcel_id"];
			else
				$arDocs["docs"] = base64_decode($docsRes["data"]);
			
			if (!empty($labelRes["data"]["errors"]))
				$errors[] = $labelRes["data"]["errors"]["order_id"];
			else
				$arDocs["labels"] = base64_decode($labelRes["data"]);
			
			if (empty($arDocs))
				CIPOLYadostHelper::throwException("error", $errors);
			
			
			if (!file_exists($filePath))
				mkdir($filePath);
			
			$arReturn = array();
			foreach ($arDocs as $docType => $docVal)
				if (false === file_put_contents($fileNames[$docType], $arDocs[$docType]))
					CIPOLYadostHelper::throwException("Can't write file", array("filePath" => $fileNames[$docType]));
				else
					$arReturn[$docType] = "/upload/" . self::$MODULE_ID . "/" . self::getOrderDocsNumber($docType, $orderID) . ".pdf";
			
			return $arReturn;
		}
		
		return array(
			"docs" => "/upload/" . self::$MODULE_ID . "/" . self::getOrderDocsNumber("docs", $orderID) . ".pdf",
			"labels" => "/upload/" . self::$MODULE_ID . "/" . self::getOrderDocsNumber("labels", $orderID) . ".pdf"
		);
	}
	
	// ��������� ��������� ���������� ������� ������� �� �����
	
	static public function delOrderDocuments()
	{
		$dirPath = $_SERVER['DOCUMENT_ROOT'] . "/upload/" . self::$MODULE_ID . "/";
		$dirContain = scandir($dirPath);
		foreach ($dirContain as $contain)
		{
			if (strpos($contain, '.pdf') !== false && (time() - (int)filemtime($dirPath . $contain)) > 1300)
				unlink($dirPath . $contain);
		}
	}
	
	// ��������� ��������� ��������� �������� � ������� ����������
	
	static public function getOrderDocs($orderID)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		self::getOrderConfirm($orderID);
		
		if (empty(self::$tmpOrderConfirm["savedParams"]["parcel_ID"]))
			CIPOLYadostHelper::throwException("Order not confirm in yandex");
		
		$arSend = array(
			"parcel_id" => self::$tmpOrderConfirm["savedParams"]["parcel_ID"],
		);
		
		$method = "getSenderParcelDocs";
		$res = self::MakeRequest($method, $arSend);
		
		return $res;
	}
	
	// ������ ������
	static public function getOrderLabels($orderID)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		self::getOrderConfirm($orderID);
		
		if (empty(self::$tmpOrderConfirm["savedParams"]["delivery_ID"]))
			CIPOLYadostHelper::throwException("Order not found in yandex");
		
		$arSend = array(
			"order_id" => self::$tmpOrderConfirm["savedParams"]["delivery_ID"],
			"is_raw" => 0, //0 � ����� � ������� PDF; , 1 � ����� � ������� HTML
		);
		
		$method = "getSenderOrderLabel";
		$res = self::MakeRequest($method, $arSend);
		
		return $res;
	}
	
	// �������� ���������� � ������
	
	static public function getOrderStatus($input)
	{
		$error = array("error" => true);
		
		if (!self::$agentCall)
			if (!CIPOLYadostHelper::isAdmin("R"))
			{
				$error["msg"] = "Access denied";
				
				return $error;
			}
		
		if (empty($input["bitrix_ID"]) && empty($input["delivery_ID"]))
		{
			$error["msg"] = "Empty order ID";
			
			return $error;
		}
		
		if ($input["bitrix_ID"])
		{
			self::getOrderConfirm($input["bitrix_ID"]);
			
			if (empty(self::$tmpOrderConfirm["savedParams"]["delivery_ID"]))
			{
				$error["msg"] = "Order not found in yandex";
				
				return $error;
			}
			
			$arSend = array(
				"order_id" => self::$tmpOrderConfirm["savedParams"]["delivery_ID"]
			);
		}
		else
			$arSend = array(
				"order_id" => $input["delivery_ID"]
			);
		
		$method = "getSenderOrderStatus";
		$res = self::MakeRequest($method, $arSend);
		
		return $res["data"];
	}
	/////////////////////////////////////////////////////////////////////////////
	// ��������������� ������ ��� ��������� ������
	/////////////////////////////////////////////////////////////////////////////
	
	static public function sendOrderDraft($orderID)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		// �������� ������ � ������
		self::fillOrderData($orderID);
		
		// ��������� ������ ������
		$arSend["recipient_first_name"] = self::getRecipientField("fname");
		$arSend["recipient_middle_name"] = self::getRecipientField("mname");
		$arSend["recipient_last_name"] = self::getRecipientField("lname");
		
		$arSend["recipient_phone"] = self::getRecipientField("phone");
		$arSend["recipient_email"] = self::getRecipientField("email");
		$arSend["order_comment"] = self::$tmpOrder["USER_DESCRIPTION"];
		
		$arSend["order_num"] = self::getOrderNum($orderID);
		
		$arSend["order_requisite"] = self::$requestConfig["requisite_id"][0];
		// $arSend["order_warehouse"] = self::$requestConfig["warehouse_id"][0];
		$arSend["order_warehouse"] = self::$requestConfig["warehouse_id"][self::$tmpOrderConfirm["formData"]["warehouseConfigNum"]];
		
		
		// ��������� ���������
		$orderPrice = floatVal(self::$tmpOrder["PRICE"]) - floatval(self::$tmpOrder["PRICE_DELIVERY"]);
		$assessedCost = $orderPrice * (self::$assessedCostPercent / 100);
		
		$arSend["order_assessed_value"] = $assessedCost;
		
		// �������� ����������
		if ("Y" == self::$tmpOrder["PAYED"])
			$arSend["order_amount_prepaid"] = self::$tmpOrder["PRICE"];
		else
			$arSend["order_amount_prepaid"] = 0;
		
		// ��������� ��������
		$arSend["order_delivery_cost"] = self::$tmpOrder["PRICE_DELIVERY"];
		
		$arSend["is_manual_delivery_cost"] = 1;//== �������, ��������. ��� ������, ��� �� �� ������ ��������� ��������� ��������, � �� ��������� ����� ���� ������ ��� �������� �� ������� ������. ���� ������, ��� ��������� ������, ����� ��������� �������� � ������� � �� ������ �� ����������.
		
		$arSend["deliverypoint_city"] = self::$tmpOrderConfirm["widgetData"]["yadostCity"];
		
		// ����� ��������
		if (self::$tmpOrderConfirm["widgetData"]["type"] != "PICKUP")
		{
			$arSend["deliverypoint_street"] = self::getRecipientField("street");
			$arSend["deliverypoint_house"] = self::getRecipientField("house");
			$arSend["deliverypoint_housing"] = self::getRecipientField("housing");
			$arSend["deliverypoint_build"] = self::getRecipientField("build");
			$arSend["deliverypoint_flat"] = self::getRecipientField("flat");
			$arSend["deliverypoint_index"] = self::getRecipientField("index");
		}
		
		if (self::$tmpOrderConfirm["widgetData"]["type"] != "TODOOR")
			$arSend["delivery_pickuppoint"] = self::$tmpOrderConfirm["widgetData"]["pickuppointId"];
		
		if (!preg_match("/^[\d]{6,6}$/", $arSend["deliverypoint_index"]))//== �������
			$arSend["deliverypoint_index"] = "";
		
		// ������ �� ������ ��������
		$arSend["delivery_delivery"] = self::$tmpOrderConfirm["widgetData"]["delivery"]["id"];
		$arSend["delivery_direction"] = self::$tmpOrderConfirm["widgetData"]["direction"];
		$arSend["delivery_tariff"] = self::$tmpOrderConfirm["widgetData"]["tariffId"];
		
		// �������� ��������
		// $arSend["delivery_interval"] = self::$tmpOrderConfirm["widgetData"]["deliveryIntervals"][0]["id"];
		$arSend["delivery_interval"] = self::$tmpOrderConfirm["widgetData"]["deliveryIntervalId"];
		
		
		// ������������ �� ����� ��� ������ ������
		$arSend["delivery_to_yd_warehouse"] = ("Y" == self::$options["to_yd_warehouse"]) ? 1 : 0;
		
		// ������� ������
		$arSend["order_items"] = array();
		foreach (self::$tmpOrderBasket as $arBasket)
		{
			$arSend["order_items"][] = array(
				"orderitem_article" => $arBasket["artnumber"],
				"orderitem_name" => $arBasket["NAME"],
				"orderitem_quantity" => ceil($arBasket["QUANTITY"]),
				"orderitem_cost" => ceil($arBasket["PRICE"]),
				"orderitem_vat_value" => $arBasket["VAT_YD_ID"]
			);
		}
		
		// �������� ������
		$arGabs = array(
			"weight",
			"length",
			"width",
			"height"
		);
		
		foreach ($arGabs as $gab)
		{
			$tmpGab = self::$tmpOrderDimension[CIPOLYadostHelper::toUpper($gab)];
			
			if (self::$formData[CIPOLYadostHelper::toUpper($gab)])
				$tmpGab = self::$formData[CIPOLYadostHelper::toUpper($gab)];
			
			$arSend["order_" . $gab] = $tmpGab;
		}
		// $arSend["order_weight"] = self::$tmpOrderDimension["WEIGHT"];
		// $arSend["order_length"] = self::$tmpOrderDimension["LENGTH"];
		// $arSend["order_width"] = self::$tmpOrderDimension["WIDTH"];
		// $arSend["order_height"] = self::$tmpOrderDimension["HEIGHT"];
		
		
		// ��������� ������
		if (self::$tmpOrderConfirm["savedParams"]["delivery_ID"])
		{
			$arSend["order_id"] = self::$tmpOrderConfirm["savedParams"]["delivery_ID"];
			$method = "updateOrder";
		}
		else
			$method = "createOrder";
		
		$arSend["order_shipment_date"] = self::convertDataFromAdmin(self::$formData["shipment_date"]);
		
		//		die(print_r(array($arSend, self::$tmpOrderConfirm), true));
		
		$res = self::MakeRequest($method, $arSend);
		
		// ���� ��������� ����� ������ � ������
		if ($res["status"] == "ok" && $res["data"]["order"]["id"])
		{
			$dbRes = CIPOLYadostSqlOrders::updateCustom(
				array("ORDER_ID" => $orderID),
				array("delivery_ID" => $res["data"]["order"]["id"])
			);
			if (!$dbRes)
				CIPOLYadostHelper::throwException(CIPOLYadostSqlOrders::getErrorMessagesCustom(), array("method" => $method, "request" => $arSend, "result" => $res));
		}
		else
			CIPOLYadostHelper::throwException("Draft order error", array("method" => $method, "request" => $arSend, "result" => $res));
		
		return $res;
	}
	
	static public function getOrderInfo($orderID)
	{
		//		if (!CIPOLYadostHelper::isAdmin())
		//			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		self::getOrderConfirm($orderID);
		
		if (empty(self::$tmpOrderConfirm["savedParams"]["delivery_ID"]))
			CIPOLYadostHelper::throwException("Order not found in yandex");
		
		$arSend = array(
			"order_id" => self::$tmpOrderConfirm["savedParams"]["delivery_ID"]
		);
		
		$method = "getOrderInfo";
		$res = self::MakeRequest($method, $arSend);
		
		return array("OrderInfo" => $res, "saveParams" => self::$tmpOrderConfirm);
	}
	
	static public function getWarehouseInfo($warehouseID)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($warehouseID))
			CIPOLYadostHelper::throwException("Empty warehouseID");
		
		$arSend = array(
			"warehouse_id" => $warehouseID
		);
		
		$method = "getWarehouseInfo";
		$res = self::MakeRequest($method, $arSend);
		
		return array("warehouseInfo" => $res);
	}// ������ ������� �� �������� �� ������
	
	static public function getSenderInfo($senderID)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($senderID))
			CIPOLYadostHelper::throwException("Empty senderID");
		
		$arSend = array();
		
		// ��������� �������, ����� �������� ��� ������
		self::getRequestConfig();
		self::$requestConfig["sender_id"][COption::GetOptionString(self::$MODULE_ID, 'defaultSender', '0')] = $senderID;
		
		$method = "getSenderInfo";
		$res = self::MakeRequest($method, $arSend);
		
		return array("clientInfo" => $res);
	}
	
	public static function getRequisiteInfo()
	{
		//		if (!CIPOLYadostHelper::isAdmin("R"))
		//			CIPOLYadostHelper::throwException("Access denied");
		
		self::getRequestConfig();
		$requisiteID = self::$requestConfig["requisite_id"][0];
		
		if ($requisiteID)
		{
			$arSend = array(
				"requisite_id" => $requisiteID
			);
			
			$method = "getRequisiteInfo";
			$res = self::MakeRequest($method, $arSend);
		}
		else
			$res = null;
		
		
		return array("requisiteInfo" => $res);
	}
	
	static public function confirmOrder($orderID, $deliveryType, $shipmentDate = false)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		if (empty($deliveryType))
			CIPOLYadostHelper::throwException("Empty deliveryType");
		
		self::getOrderConfirm($orderID);
		
		if (!$shipmentDate)
			$shipmentDate = self::getShipmentDate();//== ������\
		
		if (empty(self::$tmpOrderConfirm["savedParams"]["delivery_ID"]))
			CIPOLYadostHelper::throwException("Order not found in yandex", array("data" => self::$tmpOrderConfirm));
		
		$arSend = array(
			"order_ids" => self::$tmpOrderConfirm["savedParams"]["delivery_ID"],
			"shipment_date" => $shipmentDate,
			"type" => $deliveryType // import - ���������� ������� , withdraw - �����
		);
		
		$method = "confirmSenderOrders";
		$res = self::MakeRequest($method, $arSend);
		
		// ���� ��������� ����� ��������
		if ($res["status"] == "ok" && empty($res["data"]["result"]["error"]))
		{
			foreach ($res["data"]["result"]["success"] as $parcel)
				if (!empty($parcel["parcel_id"]) && !empty($parcel["orders"]))
				{
					$dbRes = CIPOLYadostSqlOrders::updateCustom(
						array(
							"ORDER_ID" => $orderID
						),
						array(
							"parcel_ID" => $parcel["parcel_id"]
						)
					);
					
					if (!$dbRes)
						CIPOLYadostHelper::throwException(CIPOLYadostSqlOrders::getErrorMessagesCustom(), array("method" => $method, "request" => $arSend, "result" => $res));
				}
		}
		else
			CIPOLYadostHelper::throwException("Confirm order error", array("method" => $method, "request" => $arSend, "result" => $res));
		
		return $res;
	}
	
	// ��������� ��� ���������� ������ � ������� ������
	
	static public function createDeliveryOrder($orderID, $deliveryType, $importType)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($orderID))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		if (empty($deliveryType))
			CIPOLYadostHelper::throwException("Empty deliveryType");
		
		if (empty($importType))
			CIPOLYadostHelper::throwException("Empty importType");
		
		self::getOrderConfirm($orderID);
		self::getModuleSetups();
		self::getRequestConfig();
		self::getOrderBasket(array("ORDER_ID" => $orderID));
		
		if (empty(self::$tmpOrderConfirm["savedParams"]["delivery_ID"]))
			CIPOLYadostHelper::throwException("Order not found in yandex");
		
		// ����� �������, �������� � �����������(��� ����������), ����� � ���.�
		$volume = self::$tmpOrderDimension["LENGTH"] * self::$tmpOrderDimension["LENGTH"] * self::$tmpOrderDimension["LENGTH"] / 1000000;
		
		$method = false;// �����, ������� ������������ �����
		
		// $intervals = self::getInterval(self::$tmpOrderConfirm["widgetData"]["delivery"]["unique_name"], $deliveryType);
		
		$arSend = array(
			"shipment_date" => self::getShipmentDate(),
			"interval" => self::$tmpOrderConfirm["formData"]["interval"],
			"delivery_name" => self::$tmpOrderConfirm["widgetData"]["delivery"]["unique_name"],
			"warehouse_from_id" => self::$requestConfig["warehouse_id"][self::$tmpOrderConfirm["formData"]["warehouseConfigNum"]],
			"warehouse_to_id" => self::$tmpOrderConfirm["formData"]["deliveries"],///////== !!!!!!!!!!!!
			// "warehouse_to_id" => 1081,///////== !!!!!!!!!!!!
			"requisite_id" => self::$requestConfig["requisite_id"][0],
			"weight" => self::$tmpOrderDimension["WEIGHT"],
			"volume" => $volume,
			"type" => $deliveryType,
			"sort" => 0 // ������������ ���������� �� ������ ������
		);
		
		// if (self::$options["to_yd_warehouse"])
		// $arSend["delivery_name"] = "fulfillment_".$arSend["delivery_name"];
		// else
		// $arSend["delivery_name"] = "delivery_".$arSend["delivery_name"];
		
		if ($deliveryType == "withdraw")// ����� ��������
			$method = "createWithdraw";
		elseif ($deliveryType == "import")// ����������
		{
			$method = "createImport";
			
			$arSend["name"] = array(self::$options["COURIER"]["courier_name"]);
			$arSend["import_type"] = $importType;//courier � ����� ������; , car � ������������� ������.
			
			if ($importType == "car")
			{
				$arSend["car_number"] = self::$options["COURIER"]["car_number"];
				$arSend["car_model"] = self::$options["COURIER"]["car_model"];
			}
		}
		
		if (!$method)
			CIPOLYadostHelper::throwException("Cant't detect delivery method createWithdraw or createImport");
		
		// $arSend["order_ids"] = array(self::$tmpOrderConfirm["savedParams"]["delivery_ID"]);
		$arSend["order_ids"] = self::$tmpOrderConfirm["savedParams"]["delivery_ID"];
		
		$res = self::MakeRequest($method, $arSend);
		
		if ($res["status"] == "ok")
			return $res;
		else
			CIPOLYadostHelper::throwException("createDeliveryOrder error", array("method" => $method, "request" => $arSend, "result" => $res));
		
		return false;
	}
	
	// ������� ��� ��������� ���������� ������ � ������� ������
	
	static public function confirmParcel($parcel_id)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($parcel_id))
			CIPOLYadostHelper::throwException("confirmParcel error parcel_id empty");
		
		$arSend = array(
			"parcel_ids" => $parcel_id
		);
		
		$method = "confirmSenderParcels";
		$res = self::MakeRequest($method, $arSend);
		
		if ($res["status"] == "ok" && empty($res["data"]["result"]["error"]))
			return $res["data"]["result"];
		else
			CIPOLYadostHelper::throwException("confirmSenderParcels error", array("method" => $method, "request" => $arSend, "result" => $res));
		
		return false;
	}
	
	// ������ ������
	
	static public function getFormIntervalWarehouse($params)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($params))
			CIPOLYadostHelper::throwException("Empty params");
		
		$arResult = array();
		
		$arResult["intervals"] = self::getInterval($params["deliveryName"], $params["deliveryType"]);
		$arResult["deliveries"] = self::getDeliveries();
		
		return $arResult;
	}
	
	// �������� ������
	
	static public function getInterval($deliveryName, $deliveryType)
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($deliveryName))
			CIPOLYadostHelper::throwException("Empty deliveryName");
		
		if (empty($deliveryType))
			CIPOLYadostHelper::throwException("Empty deliveryType");
		
		$obCache = new CPHPCache();
		
		$cachename = "IPOLyadostIntervals|" . $deliveryName . "|" . $deliveryType;
		
		if ($obCache->InitCache(self::CACHE_TIME, $cachename, "/IPOLyadost/"))
			return $obCache->GetVars();
		else
		{
			$arSend = array(
				"shipment_date" => self::getShipmentDate(), //== ������\
				"delivery_name" => $deliveryName,
				"shipment_type" => $deliveryType// import - ���������� ������� , withdraw - �����
			);
			
			$method = "getIntervals";
			$res = self::MakeRequest($method, $arSend);
			
			// ���� ��������� ����� ��������
			if ($res["status"] == "ok" && !empty($res["data"]["schedules"][0]))
			{
				$arReturn = CIPOLYadostHelper::convertFromUTF($res["data"]["schedules"]);
				$obCache->StartDataCache();
				$obCache->EndDataCache($arReturn);
				
				return $arReturn;
			}
			else
				CIPOLYadostHelper::throwException("getInterval error", array("method" => $method, "request" => $arSend, "result" => $res));
		}
		
		return false;
	}
	
	// ������ ������� �� �������� ���������� ������
	
	static public function getDeliveries()
	{
		if (!CIPOLYadostHelper::isAdmin("R"))
			CIPOLYadostHelper::throwException("Access denied");
		
		$obCache = new CPHPCache();
		$cachename = "IPOLyadost";
		
		if ($obCache->InitCache(self::CACHE_TIME, $cachename, "/IPOLyadost/"))
			return $obCache->GetVars();
		else
		{
			$arSend = array();
			
			$method = "getDeliveries";
			$res = self::MakeRequest($method, $arSend);
			
			// ���� ��������� ����� ��������
			if ($res["status"] == "ok" && !empty($res["data"]["deliveries"]))
			{
				$arReturn = CIPOLYadostHelper::convertFromUTF($res["data"]["deliveries"]);
				
				$arReturn["selectedDeliveries"] = COption::GetOptionString(self::$MODULE_ID, "deliveries", "");
				
				$obCache->StartDataCache();
				$obCache->EndDataCache($arReturn);
				
				return $arReturn;
			}
			else
				CIPOLYadostHelper::throwException("getDeliveries error", array("method" => $method, "request" => $arSend, "result" => $res));
		}
		
		return false;
	}
	
	// �������� ����� ������ � �������, �� ����� �� ��������� � ID
	
	static public function cancelOrder($params)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		if (empty($params["ORDER_ID"]))
			CIPOLYadostHelper::throwException("Empty order ID");
		
		$orderID = $params["ORDER_ID"];
		
		$orderInfo = CIPOLYadostSqlOrders::getList(array(
			"filter" => array("ORDER_ID" => $orderID)
		))->Fetch();
		
		if ($orderInfo["delivery_ID"])
		{
			$method = "deleteOrder";
			$arSend = array(
				"order_id" => $orderInfo["delivery_ID"]
			);
			
			$res = self::MakeRequest($method, $arSend);
			
			$status = self::getOrderStatus(array("delivery_ID" => $orderInfo["delivery_ID"]));
			
			if ($status == "CANCELED")
			{
				$dbRes = CIPOLYadostSqlOrders::updateCustom(
					array(
						"ORDER_ID" => $orderID
					),
					array(
						"delivery_ID" => null,
						"parcel_ID" => null
					)
				);
				
				if (!$dbRes)
					CIPOLYadostHelper::throwException(CIPOLYadostSqlOrders::getErrorMessagesCustom(), array("method" => $method, "request" => $arSend, "result" => $res));
				
				self::updateOrderStatus(array($orderID => "NEW"));
			}
			
			return array("result" => $res["data"], "STATUS" => "NEW");
		}
		
		self::updateOrderStatus(array($orderID => "NEW"));
		
		return array("STATUS" => "NEW");
	}
	
	// ��������� ������� ������ � ����������
	/*
	$params = array(
		"ORDER_ID" => 1 - �� ������
		"PRODUCT_ID" => 1 - �� ������
		������ ������ - ������� ������� ������� �����
		"PRODUCT_QUANTITY" => 1 ���������� ������
	);
	*/
	
	static public function sendStatistic($params)
	{
		// ���������� ��������
		$allowTypes = array(
			"install",// - ��������� ������ � CMS
			"activate",// - ��������� ������ �������� ������.��������
			"deactivate",// - ����������� ������ �������� ������.��������
			"update",// - ���������� ������
			"settings",// ��������� �������� ������ �������������
			"remove",// �������� ������
		);
		
		self::getModuleSetups();
		
		$type = $params["type"];
		if (!in_array($type, $allowTypes))
			return false;
		
		$info = CModule::CreateModuleObject('main');
		$mainVersion = $info->MODULE_VERSION;
		
		$info = CModule::CreateModuleObject('ipol.yadost');
		$moduleVersion = $info->MODULE_VERSION;
		
		$arSend = array(
			"type" => $type,
			"cms" => array(
				"name" => "Bitrix",
				"version" => $mainVersion,
				"module_version" => $moduleVersion
			),
			// "time" => date(DateTime::ISO8601),
			"time" => date("Y-m-d\TH:i:sP"),
			"domain" => $_SERVER["HTTP_HOST"],
			"settings" => array_merge(self::$options, array("DELIVERY_ACTIVE" => CIPOLYadostHelper::isActive())),
			"unique_key" => COption::GetOptionString(self::$MODULE_ID, "unique_num")
		);
		
		$method = "sendModuleEvent";
		try
		{
			self::MakeRequest($method, $arSend);
			
			return true;
		}
		catch (Exception $e)
		{
			return false;
		}
	}
	
	static public function fillOrderData($orderID)
	{
		self::getOrder($orderID);
		self::getOrderProps($orderID);
		self::getOrderConfirm($orderID);
		self::getRequestConfig();
		self::getOrderBasket(array("ORDER_ID" => $orderID));
	}
	
	// ������� ��������� ���������� �� �������, ��� - ��������� ������
	
	static public function clearOrderData()
	{
		self::$tmpOrder = false;
		self::$tmpOrderProps = false;
		self::$tmpOrderConfirm = false;
		self::$tmpOrderBasket = false;
		self::$tmpOrderDimension = false;
	}
	
	// ��������� ��������� ������ � ����
	
	static public function getOrder($orderID)
	{
		self::getModuleSetups();
		if (empty(self::$tmpOrder))
		{
			if (!CModule::IncludeModule("sale"))
				CIPOLYadostHelper::throwException("Module sale not found");
			
			$arOrder = CSaleOrder::GetList(
				array(),
				array("ID" => $orderID)
			)->Fetch();
			
			if (empty($arOrder))
				CIPOLYadostHelper::throwException("Order not found", array("ORDER_ID" => $orderID));
			
			self::$tmpOrder = $arOrder;
			
			foreach (GetModuleEvents(self::$MODULE_ID, "onGetOrderData", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, Array(self::$tmpOrder));
		}
		
		return self::$tmpOrder;
	}
	
	static public function getOrderProps($orderID)
	{
		self::getModuleSetups();
		if (empty(self::$tmpOrderProps))
		{
			if (!CModule::IncludeModule("sale"))
				CIPOLYadostHelper::throwException("Module sale not found");
			
			$dbOrderProps = CSaleOrderPropsValue::GetList(
				array(),
				array("ORDER_ID" => $orderID)
			);
			
			$arNeedUserPropsCode = self::$options["ADDRESS"];
			
			
			$userProps = array();
			while ($arProps = $dbOrderProps->Fetch())
			{
				$allProps[] = $arProps;
				foreach ($arNeedUserPropsCode as $key => $code)
					if ($arProps["CODE"] == $code)
						$userProps[$key] = $arProps["VALUE"];
			}
			
			if ($userProps["fname"] == $userProps["mname"] && $userProps["fname"] == $userProps["lname"])
			{
				$arName = explode(" ", $userProps["fname"]);
				
				$userProps["lname"] = $arName[0];
				
				if (!empty($arName[1]))
					$userProps["fname"] = $arName[1];
				else
					$userProps["fname"] = " ";
				
				if (!empty($arName[2]))
					$userProps["mname"] = $arName[2];
				else
					$userProps["mname"] = " ";
			}
			
			if (empty($userProps["mname"]))
				$userProps["mname"] = "";
			if (empty($userProps["lname"]))
				$userProps["lname"] = "";
			
			if (!preg_match("/^[\d]{6,6}$/", $userProps["index"]))
				$userProps["index"] = "";
			
			self::$tmpOrderProps = $userProps;
			
			foreach (GetModuleEvents(self::$MODULE_ID, "onGetOrderProps", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, Array(self::$tmpOrderProps));
		}
		
		return self::$tmpOrderProps;
	}
	
	static public function getOrderConfirm($orderID)
	{
		self::getModuleSetups();
		if (empty(self::$tmpOrderConfirm))
		{
			$sqlOrder = CIPOLYadostSqlOrders::getList(array(
				"filter" => array("ORDER_ID" => $orderID)
			))->Fetch();
			
			if (!$sqlOrder)
				return false;
			
			self::$tmpOrderConfirm["widgetData"] = CIPOLYadostHelper::convertFromUTF(json_decode(CIPOLYadostHelper::convertToUTF($sqlOrder["PARAMS"]), true));
			
			self::$tmpOrderConfirm["formData"] = CIPOLYadostHelper::convertFromUTF(json_decode(CIPOLYadostHelper::convertToUTF($sqlOrder["MESSAGE"]), true));
			
			unset($sqlOrder["PARAMS"]);
			unset($sqlOrder["MESSAGE"]);
			
			// ��������� ��������� ����������� ���������
			foreach ($sqlOrder as $key => $param)
				self::$tmpOrderConfirm["savedParams"][$key] = $param;
			
			foreach (GetModuleEvents(self::$MODULE_ID, "onGetWidgetData", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, Array(self::$tmpOrderConfirm));
		}
		
		return self::$tmpOrderConfirm;
	}
	
	static public function getOrderNum($orderID)
	{
		return $orderID;
		
		//		self::getModuleSetups();
		//		self::getOrder($orderID);
		//
		//		if (self::$tmpOrder["ACCOUNT_NUMBER"])
		//			$accountNumber = self::$tmpOrder["ACCOUNT_NUMBER"];
		//		else
		//			$accountNumber = self::$tmpOrder["ID"];
		//
		//		return $accountNumber;
	}
	
	// ��������� ������� ������ ������
	
	static public function getOrderBasket($params)
	{
		self::getModuleSetups();
		if (empty(self::$tmpOrderBasket))
		{
			self::$tmpOrderID = null;
			
			if (!CModule::IncludeModule("sale"))
				CIPOLYadostHelper::throwException("Module sale not found");
			
			if ($params["PRODUCT_ID"])
			{
				if (!CModule::IncludeModule("catalog"))
					CIPOLYadostHelper::throwException("Module catalog not found");
				
				$arProduct = CCatalogProduct::GetList(
					array(),
					array("ID" => $params["PRODUCT_ID"]),
					false,
					array("nTopCount" => 1)
				)->Fetch();
				
				$orderBasket = array(
					$params["PRODUCT_ID"] => array(
						"PRODUCT_ID" => $arProduct["ID"],
						"NAME" => $arProduct["ELEMENT_NAME"],
						"VAT_INCLUDED" => $arProduct["VAT_INCLUDED"],
						"WEIGHT" => $arProduct["WEIGHT"],
						"QUANTITY" => ($params["PRODUCT_QUANTITY"]) ? $params["PRODUCT_QUANTITY"] : 1,
						"DIMENSIONS" => Array
						(
							"WIDTH" => $arProduct["WIDTH"],
							"HEIGHT" => $arProduct["HEIGHT"],
							"LENGTH" => $arProduct["LENGTH"]
						),
						"SET_PARENT_ID" => $arProduct["SET_PARENT_ID"]
					)
				);
				
				$dbPrice = CPrice::GetList(
					array("QUANTITY_FROM" => "ASC", "QUANTITY_TO" => "ASC", "SORT" => "ASC"),
					array("PRODUCT_ID" => $params["PRODUCT_ID"]),
					false,
					false,
					array("ID", "CATALOG_GROUP_ID", "PRICE", "CURRENCY", "QUANTITY_FROM", "QUANTITY_TO")
				);
				
				while ($arPrice = $dbPrice->Fetch())
				{
					$orderBasket[$params["PRODUCT_ID"]]["BASE_PRICE"] = $arPrice["PRICE"];
					$orderBasket[$params["PRODUCT_ID"]]["CURRENCY"] = $arPrice["CURRENCY"];
					
					$arDiscounts = CCatalogDiscount::GetDiscountByPrice(
						$arPrice["ID"]
					);
					
					$orderBasket[$params["PRODUCT_ID"]]["PRICE"] = CCatalogProduct::CountPriceWithDiscount(
						$arPrice["PRICE"],
						$arPrice["CURRENCY"],
						$arDiscounts
					);
				}
				
				$vatID = CIPOLYadostHelper::getVatIDDefault();
				if ($arProduct["VAT_ID"])
				{
					$arVat = CCatalogVat::getListEx(
						array($by => "ID"),
						array(
							"ID" => $arProduct["VAT_ID"]
						),
						false,
						false,
						array()
					)->Fetch();
					
					if ($arVat)
					{
						$vatID = CIPOLYadostHelper::getVatID((int) $arVat["RATE"]);
					}
				}
				
				$orderBasket["VAT_YD_ID"] = $vatID;
			}
			else
			{
				$arFilter = array("ORDER_ID" => "0");
				
				// ��������� ������ �� ����������
				if ($params["ORDER_ID"])
				{
					self::$tmpOrderID = $params["ORDER_ID"];
					
					$arFilter = array(
						"ORDER_ID" => $params["ORDER_ID"]
					);
				}
				elseif (empty($params))
					$arFilter = array(
						"FUSER_ID" => CSaleBasket::GetBasketUserID(),
						"LID" => SITE_ID,
						"ORDER_ID" => "NULL",
						"CAN_BUY" => "Y",
						"DELAY" => "N"
					);
				
				
				$dbBasket = CSaleBasket::GetList(
					array(),
					$arFilter
				);
				
				$orderBasket = array();
				while ($arBasket = $dbBasket->Fetch())
				{
					$arBasket["DIMENSIONS"] = unserialize($arBasket["DIMENSIONS"]);
					$orderBasket[$arBasket["PRODUCT_ID"]] = $arBasket;
					
					$orderBasket[$arBasket["PRODUCT_ID"]]["VAT_YD_ID"] = CIPOLYadostHelper::getVatID($arBasket["VAT_RATE"]);
				}
			}
			
			// ���� ������� ���������� ������, �� ����� �� ��� ���� ����� � ���������� ����������
			if (empty($orderBasket))
			{
				$orderBasket = array(
					array(
						"ID" => 0,
						"WEIGHT" => self::$options["weightD"],
						"DIMENSIONS" => array(
							"WIDTH" => self::$options["widthD"],
							"HEIGHT" => self::$options["heightD"],
							"LENGTH" => self::$options["lengthD"]
						),
						"QUANTITY" => 1,
						"PRICE" => 1000,
						"VAT_YD_ID" => CIPOLYadostHelper::getVatIDDefault()
					)
				);
			}
			else
			{
				// ������������ ���������
				$orderBasket = self::handleBitrixComplects($orderBasket);
				
				// ���� ������� �������� �������, �� ����������� ���
				$artnumberCode = self::$options["artnumber"];
				
				// ���� �������� � ��������, �� ����������� ��
				$sideMode = self::$options["sideMode"];
				
				// ���� �������� � ��������, �� ����������� ��
				$weightMode = self::$options["weightPr"];
				
				if ($artnumberCode || $sideMode != "def" || $weightMode != "CATALOG_WEIGHT")
				{
					if (!CModule::IncludeModule("iblock"))
						CIPOLYadostHelper::throwException("Module iblock not found");
					
					$productIDs = array();
					// �������� id ������� � �������
					foreach ($orderBasket as $arBasket)
						$productIDs[] = $arBasket["PRODUCT_ID"];
					
					// ���������, ����� �������� �������� �� �������
					$arSelect = array("ID", "IBLOCK_ID");
					
					// �������
					if ($artnumberCode)
						if ($artnumberCode != "ID")
							$arSelect[] = "PROPERTY_" . $artnumberCode;
					
					// ��������
					if ($sideMode == "unit")
						$arSelect[] = "PROPERTY_" . self::$options["sidesUnit"];
					elseif ($sideMode == "sep")
					{
						$arSelect[] = "PROPERTY_" . self::$options["sidesSep"]["L"];
						$arSelect[] = "PROPERTY_" . self::$options["sidesSep"]["W"];
						$arSelect[] = "PROPERTY_" . self::$options["sidesSep"]["H"];
					}
					
					// ���
					if ($weightMode != "CATALOG_WEIGHT")
						$arSelect[] = "PROPERTY_" . self::$options["weightPr"];
					
					// ������ ������ � ��
					$dbElements = CIBlockElement::GetList(
						array(),
						array("ID" => $productIDs),
						false,
						false,
						$arSelect
					);
					
					// ��������� � ������ ������ �� �������
					while ($arElem = $dbElements->Fetch())
					{
						// �������
						if ($artnumberCode)
							if ($artnumberCode == "ID")
								$orderBasket[$arElem["ID"]]["artnumber"] = $arElem["ID"];
							else
								$orderBasket[$arElem["ID"]]["artnumber"] = $arElem["PROPERTY_" . CIPOLYadostHelper::toUpper($artnumberCode) . "_VALUE"];
						
						// ��������
						if ($sideMode == "unit")
						{
							$arDims = explode(self::$options["sidesUnitSprtr"], $arElem['PROPERTY_' . CIPOLYadostHelper::toUpper(self::$options["sidesUnit"]) . '_VALUE']);
							$orderBasket[$arElem["ID"]]["DIMENSIONS"] = array(
								"WIDTH" => $arDims[0],
								"HEIGHT" => $arDims[1],
								"LENGTH" => $arDims[2]
							);
						}
						elseif ($sideMode == "sep")
							$orderBasket[$arElem["ID"]]["DIMENSIONS"] = array(
								"WIDTH" => $arElem['PROPERTY_' . CIPOLYadostHelper::toUpper(self::$options["sidesSep"]['W']) . '_VALUE'],
								"HEIGHT" => $arElem['PROPERTY_' . CIPOLYadostHelper::toUpper(self::$options["sidesSep"]['H']) . '_VALUE'],
								"LENGTH" => $arElem['PROPERTY_' . CIPOLYadostHelper::toUpper(self::$options["sidesSep"]['L']) . '_VALUE']
							);
						
						// ���
						if ($weightMode != "CATALOG_WEIGHT")
							$orderBasket[$arElem["ID"]]["WEIGHT"] = $arElem["PROPERTY_" . CIPOLYadostHelper::toUpper(self::$options["weightPr"]) . "_VALUE"];
					}
				}
			}
			
			self::$tmpOrderBasket = $orderBasket;
			
			// �������� ��������� �������� ������, ��� ������� ����� ��� ����������
			self::getOrderDimension();
			
			foreach (GetModuleEvents(self::$MODULE_ID, "onGetBasketData", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, Array(self::$tmpOrderBasket));
			
		}
		
		return self::$tmpOrderBasket;
	}
	
	// ��������� ������� ������
	
	static public function handleBitrixComplects($goods)
	{
		$arComplects = array();
		
		foreach ($goods as $good)
			if (
				array_key_exists('SET_PARENT_ID', $good) &&
				$good['SET_PARENT_ID'] &&
				$good['SET_PARENT_ID'] != $good['ID']
			)
				$arComplects[$good['SET_PARENT_ID']] = true;
		
		foreach ($goods as $key => $good)
			if (array_key_exists($good['ID'], $arComplects))
				unset($goods[$key]);
		
		return $goods;
	}
	
	// �������� ������� � �����������
	
	static public function sumSizeOneGoods($xi, $yi, $zi, $qty)
	{
		// ������������� ����� �� �����������
		$ar = array($xi, $yi, $zi);
		sort($ar);
		if ($qty <= 1)
			return (array('X' => $ar[0], 'Y' => $ar[1], 'Z' => $ar[2]));
		
		$x1 = 0;
		$y1 = 0;
		$z1 = 0;
		$l = 0;
		
		$max1 = floor(Sqrt($qty));
		for ($y = 1; $y <= $max1; $y++)
		{
			$i = ceil($qty / $y);
			$max2 = floor(Sqrt($i));
			for ($z = 1; $z <= $max2; $z++)
			{
				$x = ceil($i / $z);
				$l2 = $x * $ar[0] + $y * $ar[1] + $z * $ar[2];
				if (($l == 0) || ($l2 < $l))
				{
					$l = $l2;
					$x1 = $x;
					$y1 = $y;
					$z1 = $z;
				}
			}
		}
		
		return (array('X' => $x1 * $ar[0], 'Y' => $y1 * $ar[1], 'Z' => $z1 * $ar[2]));
	}
	
	// �������� ��� � �����������
	
	static public function sumSize($a)
	{
		$n = count($a);
		if (!($n > 0))
			return (array('length' => '0', 'width' => '0', 'height' => '0'));
		for ($i3 = 1; $i3 < $n; $i3++)
		{
			// ������������� ������� �� ��������
			for ($i2 = $i3 - 1; $i2 < $n; $i2++)
			{
				for ($i = 0; $i <= 1; $i++)
				{
					if ($a[$i2]['X'] < $a[$i2]['Y'])
					{
						$a1 = $a[$i2]['X'];
						$a[$i2]['X'] = $a[$i2]['Y'];
						$a[$i2]['Y'] = $a1;
					};
					if (($i == 0) && ($a[$i2]['Y'] < $a[$i2]['Z']))
					{
						$a1 = $a[$i2]['Y'];
						$a[$i2]['Y'] = $a[$i2]['Z'];
						$a[$i2]['Z'] = $a1;
					}
				}
				$a[$i2]['Sum'] = $a[$i2]['X'] + $a[$i2]['Y'] + $a[$i2]['Z']; // ����� ������
			}
			// ������������� ����� �� �����������
			for ($i2 = $i3; $i2 < $n; $i2++)
				for ($i = $i3; $i < $n; $i++)
					if ($a[$i - 1]['Sum'] > $a[$i]['Sum'])
					{
						$a2 = $a[$i];
						$a[$i] = $a[$i - 1];
						$a[$i - 1] = $a2;
					}
			// ��������� ����� ��������� ���� ����� ��������� ������
			if ($a[$i3 - 1]['X'] > $a[$i3]['X'])
				$a[$i3]['X'] = $a[$i3 - 1]['X'];
			if ($a[$i3 - 1]['Y'] > $a[$i3]['Y'])
				$a[$i3]['Y'] = $a[$i3 - 1]['Y'];
			$a[$i3]['Z'] = $a[$i3]['Z'] + $a[$i3 - 1]['Z'];
			$a[$i3]['Sum'] = $a[$i3]['X'] + $a[$i3]['Y'] + $a[$i3]['Z']; // ����� ������
		}
		
		return (array(
			'L' => Round($a[$n - 1]['X'], 2),
			'W' => Round($a[$n - 1]['Y'], 2),
			'H' => Round($a[$n - 1]['Z'], 2))
		);
	}
	
	// ����������� ���� �������� �� �����
	
	static public function standartSides($side)
	{
		self::getModuleSetups();
		$side = floatVal($side);
		
		switch (self::$options["sidesMeas"])
		{
			case 'mm':
				return $side * 0.1;
			case 'dm':
				return $side * 10;
			case 'm':
				return $side * 100;
			default:
				return $side;
		}
	}
	
	static public function standartWeight($weight)
	{
		self::getModuleSetups();
		$weight = floatVal($weight);
		
		switch (self::$options["weightMeas"])
		{
			case 'g':
				$res = $weight * 0.001;
				break;
			case 't':
				$res = $weight * 1000;
				break;
			default:
				$res = $weight;
		}
		
		return $res;
	}
	
	static public function getShipmentDate($template = false)
	{
		if ($template)
			return date($template, time()/* + 24*60*60*/);
		
		return date("Y-m-d", time()/* + 24*60*60*/);
	}
	
	/////////////////////////////////////////////////////////////////////////////
	// �������, ���������
	/////////////////////////////////////////////////////////////////////////////
	
	static public function convertDataFromAdmin($str)
	{
		$arTime = explode(".", $str);
		
		return date("Y-m-d", mktime(0, 0, 0, $arTime[1], $arTime[0], $arTime[2]));
	}
	
	static public function convertDataToAdmin($str)
	{
		$arTime = explode("-", $str);
		
		return date("d.m.Y", mktime(0, 0, 0, $arTime[1], $arTime[2], $arTime[0]));
	}
	
	static public function getModuleSetups()
	{
		if (empty(self::$options))
		{
			self::$options = array(
				// "assessedCost" => COption::GetOptionString(self::$MODULE_ID, "assessedCost", 0),
				"assessedCostPercent" => FloatVal(COption::GetOptionString(CIPOLYadostDriver::$MODULE_ID, 'assessedCostPercent', '100')),
				"artnumber" => COption::GetOptionString(self::$MODULE_ID, "artnumber", ""),
				"cityFrom" => COption::GetOptionString(self::$MODULE_ID, "cityFrom", "MOSCOW"),
				"to_yd_warehouse" => COption::GetOptionString(self::$MODULE_ID, "to_yd_warehouse", ""),
				"defaultWarehouse" => COption::GetOptionString(CIPOLYadostDriver::$MODULE_ID, 'defaultWarehouse', '0'),
				
				"ADDRESS" => array(
					"fname" => COption::GetOptionString(self::$MODULE_ID, "fname", "FIO"),
					"lname" => COption::GetOptionString(self::$MODULE_ID, "lname", "FIO"),
					"mname" => COption::GetOptionString(self::$MODULE_ID, "mname", "FIO"),
					"email" => COption::GetOptionString(self::$MODULE_ID, "email", "EMAIL"),
					"phone" => COption::GetOptionString(self::$MODULE_ID, "phone", "PHONE"),
					
					"index" => COption::GetOptionString(self::$MODULE_ID, "index", "ZIP"),
					"address" => COption::GetOptionString(self::$MODULE_ID, "address", "ADDRESS"),
					"street" => COption::GetOptionString(self::$MODULE_ID, "street", "STREET"),
					"house" => COption::GetOptionString(self::$MODULE_ID, "house", "HOUSE"),
					"build" => COption::GetOptionString(self::$MODULE_ID, "build", "BUILD"),
					"flat" => COption::GetOptionString(self::$MODULE_ID, "flat", "FLAT"),
				),
				
				"sidesMeas" => COption::GetOptionString(self::$MODULE_ID, "sidesMeas", "mm"),
				"sideMode" => COption::GetOptionString(self::$MODULE_ID, "sideMode", "def"),
				
				"sidesSep" => unserialize(COption::GetOptionString(self::$MODULE_ID, "sidesSep", 'a:3:{s:1:"L";s:6:"LENGTH";s:1:"W";s:5:"WIDTH";s:1:"H";s:6:"HEIGHT";}')),
				"sidesUnit" => COption::GetOptionString(self::$MODULE_ID, "sidesUnit", "DIMESIONS"),
				"sidesUnitSprtr" => COption::GetOptionString(self::$MODULE_ID, "sidesUnitSprtr", "x"),
				"weightPr" => COption::GetOptionString(self::$MODULE_ID, "weightPr", "CATALOG_WEIGHT"),
				"weightMeas" => COption::GetOptionString(self::$MODULE_ID, "weightMeas", "kg"),
				
				"weightD" => COption::GetOptionString(self::$MODULE_ID, "weightD", "1"),
				"heightD" => COption::GetOptionString(self::$MODULE_ID, "heightD", "20"),
				"widthD" => COption::GetOptionString(self::$MODULE_ID, "widthD", "30"),
				"lengthD" => COption::GetOptionString(self::$MODULE_ID, "lengthD", "40"),
				
				"COURIER" => array(
					// "import_type" => COption::GetOptionString(self::$MODULE_ID, "import_type", "courier"),
					"courier_name" => COption::GetOptionString(self::$MODULE_ID, "courier_name", ""),
					"car_number" => COption::GetOptionString(self::$MODULE_ID, "car_number", "XX100X199"),
					"car_model" => COption::GetOptionString(self::$MODULE_ID, "car_model", "Ford"),
				)
			);
			
			$arStatuses = CIPOLYadostHelper::getDeliveryStatuses();
			
			foreach ($arStatuses as $status => $descr)
			{
				$option = COption::GetOptionString(self::$MODULE_ID, $status, "");
				if ($option)
					self::$options["STATUS"][$status] = $option;
			}
		}
		
		return true;
	}
	
	static public function sign($method)
	{
		self::getRequestConfig();
		
		$hash = '';
		
		// ��������� � ������� ���������� ��������� ��� ����������� ������������ �������
		self::$requestSend['client_id'] = self::$requestConfig["client_id"];
		self::$requestSend['sender_id'] = IntVal(self::$requestConfig["sender_id"][COption::GetOptionString(self::$MODULE_ID, 'defaultSender', '0')]);
		
		// ���������  ��������� ������� �� ������ � ���������� ������� ��� ����������� ������������ �������
		$keys = array_keys(self::$requestSend);
		sort($keys);
		
		
		// �������� ��� ��������� ������� �� 3 ������ ����������� � ���������� �������
		foreach ($keys as $key)
		{
			if (!is_array(self::$requestSend[$key]))
				$hash .= self::$requestSend[$key];
			else
			{
				$subKeys = array_keys(self::$requestSend[$key]);
				sort($subKeys);
				foreach ($subKeys as $subKey)
				{
					
					if (!is_array(self::$requestSend[$key][$subKey]))
						$hash .= self::$requestSend[$key][$subKey];
					else
					{
						$subSubKeys = array_keys(self::$requestSend[$key][$subKey]);
						sort($subSubKeys);
						foreach ($subSubKeys as $subSubKey)
						{
							if (!is_array(self::$requestSend[$key][$subKey][$subSubKey]))
							{
								$hash .= self::$requestSend[$key][$subKey][$subSubKey];
							}
						}
					}
				}
			}
		}
		
		$hash .= self::$requestConfig["keys"][$method];
		$hash = md5($hash);
		
		// ����������� ������
		self::$requestSend['secret_key'] = $hash;
	}
	
	static public function getConfigFileName()
	{
		if (!self::$configFileName)
		{
			$configFilePath = $_SERVER['DOCUMENT_ROOT'] . "/bitrix/js/" . self::$MODULE_ID . "/private/";
			
			// ����� ���������� ��������� �����
			$lastConfigFileTime = COption::GetOptionString(CIPOLYadostDriver::$MODULE_ID, "lastConfigFileTime", 0);
			$fileName = md5(CMain::GetServerUniqID() . $lastConfigFileTime);
			if (!file_exists($configFilePath . $fileName . ".conf"))
				$fileName = md5($lastConfigFileTime);
			
			global $USER;
			if (!is_object($USER))
				$USER = new CUser();
			
			// ���� ��� ��� ������, ������������� ��� ����� ����� �����
			if ($GLOBALS["USER"]->isAdmin() && file_exists($configFilePath . $fileName . ".conf"))
			{
				$curTime = time();
				// ���� ����� ����� �����, ������� ����� ��� �����
				if ($curTime - $lastConfigFileTime > 86400)
				{
					// ����� ������ ������ � ������������ � ���� � ����� ������
					$fileContent = file_get_contents($configFilePath . $fileName . ".conf");
					
					$newFileName = md5(CMain::GetServerUniqID() . $curTime);
					
					if (file_put_contents($configFilePath . $newFileName . ".conf", $fileContent))
						if (COption::SetOptionString(CIPOLYadostDriver::$MODULE_ID, "lastConfigFileTime", $curTime))
						{
							unlink($configFilePath . $fileName . ".conf");
							$fileName = $newFileName;
						}
				}
			}
			
			// �������� ����� ������ ���� � �����
			if (!file_exists($configFilePath . $fileName . ".conf"))
			{
				$arFiles = glob($configFilePath . "*.conf");
				
				$filesCount = count($arFiles);
				if ($filesCount >= 2)
					return false;
				elseif ($filesCount == 0)
					self::$configFileName = $configFilePath . $fileName . ".conf";
				else
					self::$configFileName = $arFiles[0];
			}
			else
				self::$configFileName = $configFilePath . $fileName . ".conf";
			
			self::checkOldConfig();
		}
		
		return true;
	}
	
	static public function getRequestConfig()
	{
		if (empty(self::$requestConfig))
		{
			$configFound = false;
			$iterator = 0;
			
			// ������� ��������� ������ ����, �� ����� ���������������� � ������ ������ � ��� �� ���� ���������
			while ($iterator < 10 && !$configFound)
			{
				self::getConfigFileName();
				
				$arConfig = array();
				if (file_exists(self::$configFileName))
					$arConfig = file_get_contents(self::$configFileName);
				
				$arConfig = json_decode($arConfig, true);
				
				$iterator++;
				
				if (empty($arConfig))
				{
					self::$configFileName = false;
					usleep(300000);// ��������� �������� �������� ����� 0,3 c������, ����� ������ ���������
				}
				else
					$configFound = true;
			}
			
			if (!$configFound)
				return false;
			
			// ��������� ���� ��� �������� ����������
			if (isset($arConfig["keys"]["getDeliveries"]))
				$arConfig["keys"]["sendModuleEvent"] = $arConfig["keys"]["getDeliveries"];
			
			self::$requestConfig = $arConfig;
		}
		
		return true;
	}
	
	// ������� �������
	
	public static function MakeRequest($method, $arSend)
	{
		if (!function_exists('curl_init'))
			CIPOLYadostHelper::throwException("curl not found");
		
		self::$requestSend = $arSend;
		
		// ����������� ������
		self::$requestSend = CIPOLYadostHelper::convertToUTF(self::$requestSend);
		self::sign($method);
		
		$request = http_build_query(self::$requestSend);
		
		$curl_url = 'https://delivery.yandex.ru/api/last/';
		// $curl_url = 'https://delivery.yandex.ru/api/1.0/';
		
		// ���������� ������ �� ��������� � delivery API � ��������� �����
		$curl_handle = curl_init();
		
		$headers = array("Content-Type: application/x-www-form-urlencoded", "Accept: application/json");
		curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $headers);
		
		curl_setopt($curl_handle, CURLOPT_URL, $curl_url . $method);
		curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl_handle, CURLOPT_TIMEOUT, 60);
		curl_setopt($curl_handle, CURLOPT_POST, 1);
		curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $request);
		
		curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
		
		curl_setopt($curl_handle, CURLOPT_FRESH_CONNECT, 1);
		
		$curl_answer = curl_exec($curl_handle);
		$code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
		
		curl_close($curl_handle);
		
		$arResult = json_decode($curl_answer, true);
		
		$debugInfo = array(
			"code" => $code,
			"method" => $method,
			"arSend" => self::$requestSend,
			"res" => $arResult,
			"req_url" => $curl_url . $method . "?" . $request,
			"json_request" => json_encode(self::$requestSend),
			"json_return" => $curl_answer
		);
		
		self::$debug[] = $debugInfo;
		
		CIPOLYadostHelper::errorLog(self::$debug);
		
		if ($code != 200)
		{
			return "request error";
		}
		//			CIPOLYadostHelper::throwException("request error");
		
		return $arResult;
	}
	
	// ��������� ���� ������ �����
	
	public static function setConfig($params)
	{
		if (!CIPOLYadostHelper::isAdmin())
			CIPOLYadostHelper::throwException("Access denied");
		
		$oldFormat = false;
		
		$clientParams = $params["config2"];
		$clientParams = json_decode($clientParams, true);
		if (empty($clientParams))
		{
			$oldFormat = true;
			$clientParams = $params["config2"];
			$clientParams = preg_replace("/^( )?\[/", "{", $clientParams);
			$clientParams = preg_replace("/\]( )?$/", "}", $clientParams);
			$clientParams = preg_replace("/\/\*.*?\*\//", "", $clientParams);
			$clientParams = preg_replace("/ /", "", $clientParams);
			
			$clientParams = json_decode($clientParams, true);
		}
		
		if (empty($clientParams))
			CIPOLYadostHelper::throwException("Client params error", array("decode_error" => self::json_last_error_msg(), "config2" => $params["config2"]));
		
		$keys = $params["config1"];
		$keys = json_decode($keys, true);
		if (empty($keys))
		{
			$keys = $params["config1"];
			$keys = preg_replace("/\[\n/", "", $keys);
			$keys = preg_replace("/\n\]/", "", $keys);
			$keys = preg_replace("/: /", "\": \"", $keys);
			$keys = preg_replace("/\n/", "\",\n\"", $keys);
			$keys = preg_replace("/ /", "", $keys);
			$keys = preg_replace("/\n/", "", $keys);
			$keys = "{\"" . $keys . "\"}";
			
			$keys = json_decode($keys, true);
		}
		
		if (empty($keys))
			CIPOLYadostHelper::throwException("API-keys error", array("decode_error" => self::json_last_error_msg(), "config1" => $params["config1"]));
		
		$arReplacer = array(
			"sender_ids" => "sender_id",
			"warehouse_ids" => "warehouse_id",
			"requisite_ids" => "requisite_id",
			
			//����� ������
			"client" => "client_id",
			"senders" => "sender_id",
			"warehouses" => "warehouse_id",
			"requisites" => "requisite_id",
		);
		
		foreach ($arReplacer as $key => $val)
		{
			if ($clientParams[$key])
			{
				$clientParams[$val] = $clientParams[$key];
				unset($clientParams[$key]);
			}
		}
		
		if (!$oldFormat)
			foreach ($clientParams as $key => $val)
			{
				$tmpArr = array();
				
				if (is_array($val))
					if ($val["id"])
						$clientParams[$key] = $val["id"];
					else
						foreach ($val as $value)
							if ($value["id"])
								$tmpArr[] = $value["id"];
				
				if (!empty($tmpArr))
					$clientParams[$key] = $tmpArr;
			}
		
		$arConfig = array_merge($clientParams, array("keys" => $keys));
		
		self::getConfigFileName();
		
		$configFileExist = false;
		if (file_exists(self::$configFileName))
			$configFileExist = true;
		
		file_put_contents(self::$configFileName, json_encode($arConfig, true));
		
		// ���� ������� �� ����, ������ ��� ��������� ������, ���������� ����������
		if (!$configFileExist)
			self::sendStatistic(array("type" => "install"));
		
		return true;
	}
	
	// ��������� ������� ������ ��� ���������� �������� � ���
	
	static private function getOrderDocsNumber($type, $orderID)
	{
		return md5($type . $orderID);
	}
	
	// ���������� �������
	
	static private function getRecipientField($code)
	{
		if (!empty(self::$formData[$code]))
			return self::$formData[$code];
		
		if (!empty(self::$tmpOrderConfirm["formData"][$code]))
			return self::$tmpOrderConfirm["formData"][$code];
		else
			return self::$tmpOrderProps[$code];
	}
	
	// ��������� ������ ����� �� �� ������
	
	static private function getOrderDimension()
	{
		self::getModuleSetups();
		
		if (empty(self::$tmpOrderDimension))
		{
			// ���� ���� ����� ������, ���� ��������� �� ������ �� �� ����� �������� � ������� ��
			$returnFormData = false;
			$arSavedFormParams = array();
			
			if (self::$tmpOrderID)
			{
				$dbOrders = CIPOLYadostSqlOrders::getList(array(
					"filter" => array("ORDER_ID" => self::$tmpOrderID)
				))->Fetch();
				
				if ($dbOrders)
				{
					$arDimsCheck = array(
						"WEIGHT",
						"WIDTH",
						"HEIGHT",
						"LENGTH"
					);
					
					$formData = CIPOLYadostHelper::convertFromUTF(json_decode(CIPOLYadostHelper::convertToUTF($dbOrders["MESSAGE"]), true));
					
					// ��������� ������� ����������� ���������
					$returnFormData = true;
					foreach ($arDimsCheck as $oneDim)
						if (!isset($formData[$oneDim]))
							$returnFormData = false;
					
					if ($returnFormData)
						foreach ($arDimsCheck as $oneDim)
							$arSavedFormParams[$oneDim] = $formData[$oneDim];
				}
			}
			
			self::$zeroWeightGoods = array();// id ������� � 0 �����
			self::$zeroGabsGoods = array();// id ������� � 0 ����������
			$totalWeight = 0;
			$oneGoodDims = array();
			$noWeightCount = 0; // ���������� ������� � 0 �����
			$arDefSetups = array(
				"WEIGHT" => self::standartWeight(self::$options["weightD"]),
				"LENGTH" => self::standartSides(self::$options["lengthD"]),
				"WIDTH" => self::standartSides(self::$options["widthD"]),
				"HEIGHT" => self::standartSides(self::$options["heightD"])
			);// �������� ������ �� ���������
			$totalPrice = 0;
			$totalQuantity = 0;
			
			foreach (self::$tmpOrderBasket as $prodID => $arItem)
			{
				$isZeroGab = false;
				foreach (self::$tmpOrderBasket[$prodID]["DIMENSIONS"] as $val)
					if (empty($val))
						$isZeroGab = true;
				
				if ($isZeroGab)
					self::$zeroGabsGoods[$prodID] = $prodID;
				
				// �������� �����������
				self::$tmpOrderBasket[$prodID]["DIMENSIONS"] = array(
					"WIDTH" => self::standartSides(self::$tmpOrderBasket[$prodID]["DIMENSIONS"]["WIDTH"]),
					"HEIGHT" => self::standartSides(self::$tmpOrderBasket[$prodID]["DIMENSIONS"]["HEIGHT"]),
					"LENGTH" => self::standartSides(self::$tmpOrderBasket[$prodID]["DIMENSIONS"]["LENGTH"]),
				);
				self::$tmpOrderBasket[$prodID]["WEIGHT"] = self::standartWeight(self::$tmpOrderBasket[$prodID]["WEIGHT"]);
				
				// ����������� ���
				if (floatval(self::$tmpOrderBasket[$prodID]["WEIGHT"]) == 0)
				{
					self::$zeroWeightGoods[$prodID] = $prodID;
					$noWeightCount += (int)self::$tmpOrderBasket[$prodID]["QUANTITY"];
				}
				
				$totalWeight += self::$tmpOrderBasket[$prodID]["WEIGHT"] * self::$tmpOrderBasket[$prodID]["QUANTITY"];
				
				// �������� ������ ���������, ���������� ��� ������� ��������� �������
				$oneGoodDims[] = self::sumSizeOneGoods(
					self::$tmpOrderBasket[$prodID]["DIMENSIONS"]["WIDTH"],
					self::$tmpOrderBasket[$prodID]["DIMENSIONS"]["HEIGHT"],
					self::$tmpOrderBasket[$prodID]["DIMENSIONS"]["LENGTH"],
					self::$tmpOrderBasket[$prodID]["QUANTITY"]
				);
				
				$totalQuantity += floatVal(self::$tmpOrderBasket[$prodID]["QUANTITY"]);
				$totalPrice += floatVal(self::$tmpOrderBasket[$prodID]["QUANTITY"]) * floatVal(self::$tmpOrderBasket[$prodID]["PRICE"]);
			}
			
			// ������� �������� �������
			$resultDims = self::sumSize($oneGoodDims);
			
			// ������������ � �������� � 0 �����
			if ($noWeightCount > 0)
			{
				// ������� ������� ���� ������� � 0 �����
				if ($totalWeight >= $arDefSetups['WEIGHT'])
				{
					self::$totalWeightMoreDefault = true;
					$setZeroWeight = 10 * 0.001;// ������� ��� ������� ��� ���� ��� 10�����
				}
				else
				{
					self::$totalWeightMoreDefault = false;
					$setZeroWeight = ceil(1000 * ($arDefSetups['WEIGHT'] - $totalWeight) / $noWeightCount) * 0.001;
				}
				
				// ���������� ��� � ������� ����� ������
				$totalWeight = 0;
				foreach (self::$tmpOrderBasket as $prodID => $arItem)
				{
					if ($setZeroWeight && floatval(self::$tmpOrderBasket[$prodID]["WEIGHT"]) == 0)
						self::$tmpOrderBasket[$prodID]["WEIGHT"] = $setZeroWeight;
					
					$totalWeight += self::$tmpOrderBasket[$prodID]["WEIGHT"] * self::$tmpOrderBasket[$prodID]["QUANTITY"];
				}
			}
			
			// ������ ��������
			self::$tmpOrderDimension = array(
				"WEIGHT" => $totalWeight,
				"LENGTH" => (floatVal($resultDims["L"]) != 0) ? $resultDims["L"] : $arDefSetups["LENGTH"],
				"WIDTH" => (floatVal($resultDims["W"]) != 0) ? $resultDims["W"] : $arDefSetups["WIDTH"],
				"HEIGHT" => (floatVal($resultDims["H"]) != 0) ? $resultDims["H"] : $arDefSetups["HEIGHT"],
				"PRICE" => $totalPrice,
				"QUANTITY" => $totalQuantity
			);
			
			// �������� �������� �� ����������� � �����
			if ($returnFormData && is_set($arDimsCheck) && is_array($arDimsCheck))
				foreach ($arDimsCheck as $oneDim)
					self::$tmpOrderDimension[$oneDim] = $arSavedFormParams[$oneDim];
		}
		
		return self::$tmpOrderDimension;
	}
	
	// ��������� ������� �������, ��������� ������ ��������, ��������� � ����� ���� �������
	
	private static function checkOldConfig()
	{
		$arOldConfigFiles = array(
			$_SERVER['DOCUMENT_ROOT'] . "/bitrix/js/" . self::$MODULE_ID . "/config.php",
			$_SERVER['DOCUMENT_ROOT'] . "/bitrix/js/" . self::$MODULE_ID . "/private/config.txt"
		);
		
		foreach ($arOldConfigFiles as $oldConfigFileName)
			if (file_exists($oldConfigFileName))
			{
				$arConfig = array();
				
				if (preg_match("/\.php$/", $oldConfigFileName))
					require_once($oldConfigFileName);
				else
					$arConfig = file_get_contents($oldConfigFileName);
				
				$arConfig = json_decode($arConfig, true);
				
				// ��������� ������ ������ � ����� ������������
				if (!empty($arConfig))
				{
					if (empty(self::$requestConfig))
						self::getConfigFileName();
					
					file_put_contents(self::$configFileName, json_encode($arConfig, true));
				}
				
				unlink($oldConfigFileName);
			}
	}
	
	// ��������� ������ ������ json_decode, json_encode
	private static function json_last_error_msg()
	{
		static $ERRORS = array(
			JSON_ERROR_NONE => 'No error',
			JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
			JSON_ERROR_STATE_MISMATCH => 'State mismatch (invalid or malformed JSON)',
			JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
			JSON_ERROR_SYNTAX => 'Syntax error',
			JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded'
		);
		
		$error = json_last_error();
		
		return isset($ERRORS[$error]) ? $ERRORS[$error] : 'Unknown error';
	}
}