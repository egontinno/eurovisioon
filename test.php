<?php

class ProductSync{

	private $SyncCount = 0;

	private $UpdatedCount = 0;

	private $InsertedCount = 0;

	//array of Drupal terms indexed by Vocabulary id
	private $DrupalTerms = false;

	//array of Drupal products indexed by Navi ItemNo
	private $DrupalProducts = false;

	//array of Drupal Vocabulary ids
	private $DrupalVoc = false;

	// Database connection
	private $db;

	//database connection conf
	private $conf;

	/**
     * @var string
     */
   protected $_country;
	/**
     * @var array
     */
   protected $_Products;

	/**
	 * Constructer
	 * @param $DB PDO connection object
	 */

	/**
     * @param string $value
     */
    public static function encode ( $value, $inCoding = 'CP1257' ) {
        //SQL_Latin1_General_CP1257_CS_AS
        return iconv ( $inCoding, 'UTF-8', $value );
    }

	function __construct($db = false){
		//DB connection
		$this->db = $db;
		$this->conf['picture'] = variable_get('navi_sync_picture',array());
		$this->DrupalVoc['material'] 	= variable_get('navi_sync_material_vocabulary',1);
		$this->DrupalVoc['brand'] = variable_get('navi_sync_brand_vocabulary',1);
		$this->DrupalVoc['gender'] = variable_get('navi_sync_gender_vocabulary',1);
		$this->DrupalVoc['color'] = variable_get('navi_sync_color_vocabulary',1);
		$this->DrupalVoc['group'] 	= variable_get('navi_sync_group_vocabulary',1);
		if(!$this->db){
			throw new Exception('No connection to Navi!');
		}
	}
	/**
     * @param string $shopName
     * @param string $productSku
     * @return array array(productSize => quantity, ...)
     */
    public function getProductInventory ( $productSku ) {
		if(!$this->db) return false;
		if(!$this->_country) return false;
      if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country . '_Inventory WHERE ItemNo = "' . $productSku . '"', $this->db ) )
          return new Exception ( mssql_get_last_message ( ) );

      $data = array ();
      while ( false !== $row = mssql_fetch_assoc ( $r ) ) {

         if ( 1 != $row[ 'InWalking' ] or false === $variantCode = ( int ) $row[ 'VariantCode' ] )
            continue;

			$data[ $variantCode ] = ( int ) $row[ 'Quantity' ];
      }

      return $data;
    }

	public function getProductPrices ( $productSku ) {
		if(!$this->db) return false;
		if(!$this->_country) return false;
		if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country . '_Prices WHERE ItemNo = "' . $productSku . '"', $this->db ) )
				return new Exception ( mssql_get_last_message ( ) );
			$Prices = array ();
			if (mssql_num_rows($r) > 0) {

				while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
					$id = $row[ 'ItemNo' ];
					if ( empty($id) ) continue;

					unset ( $row[ 'ItemNo' ] );
					$Prices = $row;
				}
			}
			return $Prices;
	}
	///////////////////////////////////// UPDATES //////////////////////////////////
	public function getUpdateProducts() {
		if(!$this->db) return false;
		if(!$this->_country) return false;

		if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country . '_Action ORDER BY ItemNo ASC', $this->db ) )
			return new Exception ( mssql_get_last_message ( ) );

		if (mssql_num_rows($r) > 0) {
			$Products = array ();
			while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
				$id = $row[ 'ItemNo' ];
				if ( empty($id) ) continue;

				//unset ( $row[ 'ItemNo' ] );
				$Products[ $id ] = $row;
			}

			return $Products;
		} else {
			return false;
		}
	}
	/**
	 * Get products From Navi
	 */
	public function getNaviProducts($productSku){
		if(!$this->db) return false;
		if(!$this->_country) return false;
		$where = $where_all = null;
		if(!empty($productSku)) {
			$where = 'AND ItemNo = "' . $productSku . '"';
			$where_all = 'WHERE ItemNo = "' . $productSku . '"';
		}

			///////////////////////////////////// PRODUCTS //////////////////////////////////
			if (!empty($productSku))	{
				if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country . '_Items WHERE InWalking = 1 '.$where.' ORDER BY ItemNo ASC', $this->db ) )
					return new Exception ( mssql_get_last_message ( ) );
			} else {
				#if ( false === $r = mssql_query ( 'SELECT TOP 100 * FROM WEB_' . $this->_country . '_Items WHERE InWalking = 1 '.$where.' AND ItemNo NOT IN (SELECT TOP 499 ItemNo FROM WEB_' . $this->_country . '_Items ORDER BY ItemNo) ORDER BY ItemNo ASC', $this->db ) )
				if ( false === $r = mssql_query ( 'SELECT TOP 100 * FROM WEB_' . $this->_country . '_Items WHERE InWalking = 1 '.$where.' ORDER BY ItemNo ASC', $this->db ) )
				return new Exception ( mssql_get_last_message ( ) );
			}

			 //error_log(mssql_num_rows($r));
			if (mssql_num_rows($r) > 0) {
				$Products = array ();
				while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
					$id = $row[ 'ItemNo' ];
					if ( empty($id) ) continue;

					//unset ( $row[ 'ItemNo' ] );
					$Products[ $id ] = $row;
				}
			} elseif(empty($where)) {
				return new Exception ( 'No rows on WEB_' . $this->_country . '_Items' );
			} else {
				return false;
			}
			///////////////////////////////////// DESCRIPTIONS //////////////////////////////////
			if ( false === $r = mssql_query ( 'SELECT * FROM WEB_Item_Description '.$where_all, $this->db ) )
				throw new Exception ( mssql_get_last_message ( ) );

			if (mssql_num_rows($r) > 0) {
				$Item_Description = array ();
				while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
					$id = $row[ 'ItemNo' ];
					if ( empty($id) ) continue;

					unset ( $row[ 'ItemNo' ] );
					foreach ( $row as $languageField => $value ) {

						$languageCode = explode ( '_', $languageField );
						if ( $languageCode[ 2 ] != 'Description' AND $languageCode[ 2 ] != 'Name') continue;

						if ( strtoupper ( $languageCode[ 0 ] ) == 'RU' ) {
							 $value = self::encode ( $value, 'CP1251' );

						} else {
							 $value = self::encode ( $value );
						}

						$row[ $languageField ] = $value;
				   }
					$Item_Description[ $id ] = $row;
				}
			} elseif(empty($where)) {
				return new Exception ( 'No rows on WEB_Item_Description' );
			}
			///////////////////////////////////// PRICES //////////////////////////////////
			if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country . '_Prices ' .$where_all, $this->db ) )
				return new Exception ( mssql_get_last_message ( ) );
			 //error_log(mssql_num_rows($r));
			if (mssql_num_rows($r) > 0) {
				$Prices = array ();
				while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
					$id = $row[ 'ItemNo' ];
					if ( empty($id) ) continue;

					unset ( $row[ 'ItemNo' ] );
					$Prices[ $id ] = $row;
				}
			} elseif(empty($where)) {
				return new Exception ( 'No rows on WEB_' . $this->_country . '_Prices' );
			}
			/////////////////////////////////ALL-TO-ONE-ARRAY///////////////////

			$_Products = array ();
			foreach ( $Products as $ItemNo => $Product ) {
				if (@is_array($Item_Description[ $ItemNo ])) {
					$Product['Descriptions'] = $Item_Description[ $ItemNo ];
				}
				if (@is_array($Prices[ $ItemNo ])) {
					$Product['Prices'] = $Prices[ $ItemNo ];
				}
				$_Products[ $ItemNo ] = $Product;

		}
		return $_Products;
	}
	/**
     * @param string $productSku
     * @return array array(bwtGroupNo, ...)
     */
   public function getProductGroups ( $productSku ) {
        if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country . '_Group WHERE ItemNo = "' . $productSku . '"', $this->db ) )
            return new Exception ( mssql_get_last_message ( ) );

        $data = array ();
        while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
            $data[ ] = ( int ) $row[ 'SpecialGroup' ];
        }

        return $data;
   }


	/**
     * @param string $productSku
     * @return array array(bwtGroupNo, ...)
     */
   public function getProductSale ( $productSku ) {
		$where = null;
		if(!empty($productSku)) {
			$where = 'WHERE ItemNo = "' . $productSku . '"';
		}
		if ( false === $r = mssql_query ( 'SELECT * FROM WEB_' . $this->_country.'_Sale  ' . $where, $this->db ) )
				return new Exception ( mssql_get_last_message ( ) );

		$data = array ();
		if(!empty($productSku)) {
			while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
					$data[ ] = ( int ) $row[ 'WalkingPriority' ];
			}
		} else {
			while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
					$data[ $row[ 'ItemNo' ] ][ 'WalkingPriority' ] = ( int ) $row[ 'WalkingPriority' ];
			}
		}
		return $data;
   }

	/**
     * @param string $productSku
     * @return array array(bwtGroupNo, ...)
     */
   public function getProductCount (  ) {
        if ( false === $r = mssql_query ( 'SELECT COUNT(*) as total_count FROM Webshop.Item', $this->db ) )
            return new Exception ( mssql_get_last_message ( ) );

        while ( false !== $row = mssql_fetch_assoc ( $r ) ) {
            $data = ( int ) $row[ 'total_count' ];
        }

        return $data;
   }

		/**
     * @param string $productSku
     * @return array array(bwtGroupNo, ...)
     */
    public function UpdateActions ( $row ) {
        if ( false === $r = mssql_query ( 'DELETE FROM WEB_' . $this->_country . '_Action WHERE EntryNo = '.$row['EntryNo'], $this->db ) )
            return new Exception ( mssql_get_last_message ( ) );
        return $r;
    }

	//if $sku then one product update
	//if $update = TRUE, use WEB_EE_Action table

	public function ImportProducts($sku = NULL, $update = FALSE){
		//return values
		$return = array(
			'insert' => 0,
			'update' => 0,
			'hide' => 0,
			'count'	=> 0,
			'skipped'=> 0,
			'time'   => microtime(TRUE),
		);
		//update/insert limit
		$limit = 500;
		//add delay to loop after how many counts
		$delayAfter = $delayInterval = 10;
		//timelimit on seconds
		$timelimit = 300;



		$dproducts = db_select('node', 'n');
		$dproducts->join('uc_products','p','p.nid=n.nid');

		$old =  $dproducts->fields('n')
			->fields('p')
			->condition(db_or()
				->condition('n.type','product')
				->condition('n.type','footwear')
			)
			->condition('n.language','et')
			->execute()
			->fetchAll(PDO::FETCH_OBJ);

		//index by ItemNo
		$this->DrupalProducts = array();

		foreach($old as &$p){
			$this->DrupalProducts[ $p->model.'_'.$p->language ] = $p;
		}

		if ($update == TRUE) {
			$update_products = $this->getUpdateProducts();
			// test array
			//$update_products = array();
			//$update_products[] = array('Action'=>'P', 'ItemNo'=>'715_B_ZTLP565_2');

			if( !empty($update_products) ){
				foreach($update_products as $up){
					if ($up['Action'] == 'D') {
						$product['Action'] = $up;
						if( isset($this->DrupalProducts[$up['ItemNo'].'_et']) ){

							$this->DeleteProduct( $this->DrupalProducts[$up['ItemNo'].'_et'], $product);
							$return['hide']++;
						} else {
							watchdog('navi_sync','Can\'t delete product '.$up['ItemNo'],NULL,WATCHDOG_ERROR);
							$return['skipped']++;
							$is_dev = variable_get('navi_sync_is_devpage',1);
							if( isset($product['Action']['EntryNo']  ) AND $is_dev == 0 ){
								$this->UpdateActions( $product['Action'] );
							}
						}
					} else {
						$tmp = $this->getNaviProducts($up['ItemNo']);
						$tmp[$up['ItemNo']]['Action'] = $up;
						$products[$up['ItemNo']] = $tmp[$up['ItemNo']];
					}
				}
			}
		} else {
			$products = $this->getNaviProducts($sku);
		}

		//if no products  !$products
		if( !isset($products) ){
			//watchdog('navi_sync','No products to update');
			$return['time'] = round( microtime(TRUE)-$return['time'], 3 );
			return $return;
		}

		//get existing terms
		if ( !is_array($this->DrupalTerms) ){
			$terms = db_select('taxonomy_term_data','t')
			->fields('t')
			->execute()
			->fetchAll(PDO::FETCH_OBJ);
			$this->DrupalTerms = array();
			foreach($terms as $term){
				if(!isset($this->DrupalTerms[$term->vid])) {
					$this->DrupalTerms[$term->vid] = array();
				}
				if ($term->vid == $this->DrupalVoc['group']  ) {
					$this->DrupalTerms[$term->vid][$term->navi_id][$term->language] = $term->tid;
				}elseif ($term->vid == $this->DrupalVoc['gender']  OR $term->vid == $this->DrupalVoc['color']) {
					$term->description = strip_tags($term->description);
					$this->DrupalTerms[$term->vid][$term->description][$term->language] = $term->tid;
				} else {
					$this->DrupalTerms[$term->vid][$term->name] = $term->tid;
				}
			}
		}

		//loop through each product
		foreach($products as $product){
			$return['count']++;
			//add delay after number of updates
			if( ($return['update']+$return['insert']) > $delayAfter){
				$delayAfter = $delayAfter + $delayInterval;
				watchdog('navi_sync','Sleep... Update: ' .$return['update']. ' Insert: '.$return['insert'],NULL,WATCHDOG_ALERT);
				sleep(1);
			}
			//do not update/insert over x times;
			if(($return['update']+$return['insert'])>$limit){
				error_log('---- Number of Inserted + Updated products exceeded the limit of '.$limit.', ignoring others----');
				error_log('---- Run cron again to Update all items ----');
				break;
			}
			//check for time limit
			if( (time() - $return['time']) > $timelimit ){
				error_log('---- Cron reached the time limit of '.$timelimit.' seconds ----');
				error_log('---- Run cron again to Update all items ----');
				break;
			}

			//if product exists


			if( isset($this->DrupalProducts[$product['ItemNo'].'_et']) ){
				$this->UpdateProduct( $this->DrupalProducts[$product['ItemNo'].'_et'], $product );
				$return['update']++;
			//if new product, insert
			} else{
				if( $this->UpdateProduct( NULL, $product ) ){
					$return['insert']++;
				} else {
					$return['skipped']++;
				}
			}
			$is_dev = variable_get('navi_sync_is_devpage', 1);
			if( isset($product['Action']['EntryNo']  ) AND $is_dev == 0  ){
				$this->UpdateActions( $product['Action'] );
			}

		}
		$return['time'] = round( microtime(TRUE)-$return['time'],3 );
		return $return;
	}


	/**
	 * Delete/hide product
	 * @param stdClass $node existing node Object (eestikeelne node)
	 * @param Array $values new values
	 */
	private function DeleteProduct($node, $product){

		$language[0] = 'et';
		$language[1] = 'en';
		$language[2] = 'ru';
		$language_count = count ($language);
		//load node
		if( isset($node->nid) ) {
			$n[0] = node_load($node->nid);
			$n[0]->status = 0;
			$n[0]->last_import[LANGUAGE_NONE][0]['value']  = REQUEST_TIME;
			node_save($n[0]);
			//translation
			$trans = translation_node_get_translations($n[0]->nid);
			for($i=1;$i<$language_count; $i++){
				if (isset($trans[$language[$i]]->nid)) {
					$n[$i] = node_load($trans[$language[$i]]->nid);
					$n[$i]->status = 0;
					$n[$i]->last_import[LANGUAGE_NONE][0]['value']  = REQUEST_TIME;
					node_save($n[$i]);
				}
			}
			$is_dev = variable_get('navi_sync_is_devpage',1);
			if( isset($product['Action']['EntryNo']  ) AND $is_dev == 0 ){
				$this->UpdateActions( $product['Action'] );
			}

		}	else {
			watchdog('navi_sync','No node to delete ',NULL,WATCHDOG_ALERT);
		}

	}
	/**
	 * Update product
	 * @param stdClass $node existing node Object (eestikeelne node)
	 * @param Array $values new values
	 */
	private function UpdateProduct($node, $values){
		$language[0] = 'et';
		$language[1] = 'en';
		$language[2] = 'ru';
		$navi_language[0] = 'EE_ET';
		$navi_language[1] = 'EN_GB';
		$navi_language[2] = 'RU_RU';
		$language_count = count ($language);
		$is_new = 0;
		$file_to_delete = null;
		//category
		$ProductGroups = $this->getProductGroups($values['ItemNo']);
		$ProductSale = $this->getProductSale($values['ItemNo']);

		// ET 16.02.12. Osadel toodetel on grupp 0
		foreach($ProductGroups as $key => $PG) {
			if( $PG < 1 ){
				unset($ProductGroups[$key]);
			}
		}

		if (count ($ProductGroups) < 1) {
			watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[0].'_Name']. ' ('.$values['ItemNo'].') has no group. Skipping...',NULL,WATCHDOG_ALERT);
			return false;
		}

		// ET 13.02.12. Lisa kontroll, et ei sisestataks sama toodet kaks korda!!
		if( !isset($node->nid) ){
			$dproducts = db_select('node', 'n');
			$dproducts->join('uc_products','p','p.nid=n.nid');

			$results = $dproducts->fields('n', array('nid'))
				->condition('p.model', $values['ItemNo'])
				->condition('n.language','et')
				->execute()
			  ->fetchAll(PDO::FETCH_OBJ);

			foreach($results as $r){
				if( isset($r->nid) ){
					$node->nid = $r->nid;
				}
			}
		}
		//load old node
		if( isset($node->nid) ){
			$n[0] = node_load($node->nid);
			//translation
			$trans = translation_node_get_translations($n[0]->nid);
			for($i=1;$i<$language_count; $i++){
				if (isset($trans[$language[$i]]->nid)) {
					$n[$i] = node_load($trans[$language[$i]]->nid);
				} else {
					$n[$i] = new stdClass();
					$n[$i]->type = $n[0]->type;
					node_object_prepare($n[$i]);
					$n[$i]->status = 0;
					$n[$i]->comment = 0;
					$n[$i]->promote = 0;
					$n[$i]->language = $language[$i];
					node_save($n[$i]);
				}
			}
		}
		//or create new
		else{
			for($i=0;$i<$language_count; $i++){
				$n[$i] = new stdClass();
				foreach($ProductGroups as $PG) {
					if( isset( $this->DrupalTerms[ $this->DrupalVoc['group'] ][ $PG ][$language[$i]] ) ){
						if ($PG >= 9000  ) {
							$n[$i]->type ='product'; //accessory
						} else {
							$n[$i]->type ='footwear';
						}
						break;
					} else {
						watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching group ('.$PG.')',NULL,WATCHDOG_ALERT);
					}
				}
				node_object_prepare($n[$i]);
				$n[$i]->status = 0;
				$n[$i]->comment = 0;
				$n[$i]->promote = 0;
				$n[$i]->language = $language[$i];
				node_save($n[$i]);
			}
			$is_new = 1;
		}
		//stock
		$stock_size = $this->getProductInventory($values['ItemNo']);


		$size_attribues_id = 1;

		if ($n[0]->type == 'footwear') {
			$class_attributes = uc_attribute_load($size_attribues_id, 'footwear', 'class');
		}
		//error_log (print_r($class_attributes, TRUE));

		for($i=0;$i<$language_count; $i++){

			if(!$n[$i]->uid) $n[$i]->uid = 1;
			//SKU, price, stock value
			$n[$i]->model = $values['ItemNo'];
			$n[$i]->weight =  0;
			$n[$i]->street1 =  '';
			$n[$i]->length =  0;
			$n[$i]->width =  0;
			$n[$i]->height =  0;
			$n[$i]->list_price = 0;
			$n[$i]->shippable = 1;


			$has_DiscountedPrice = 0;
			////////////////////////////////////////////////// HINNAD //////////////////////////////////
			if (isset($values['Prices'])) {
				if (isset($values['Prices']['DiscountedPrice']) AND $values['Prices']['DiscountedPrice'] > 0) {
					$n[$i]->sell_price = $values['Prices']['DiscountedPrice'];
					if ($values['Prices']['DiscountedPrice'] < $values['Prices']['ProductPrice']) {
						$n[$i]->field_old_price[$n[$i]->language][0]['value'] = $values['Prices']['ProductPrice'];
						$n[$i]->field_saving[LANGUAGE_NONE][0]['value'] = $values['Prices']['ProductPrice'] - $values['Prices']['DiscountedPrice'];
						$has_DiscountedPrice = 1;

					} else {
						$n[$i]->sell_price = $values['Prices']['ProductPrice'];
						unset($n[$i]->field_old_price[$n[$i]->language][0]);
						unset($n[$i]->field_saving[LANGUAGE_NONE][0]);
					}
				} else {
					$n[$i]->sell_price = $values['Prices']['ProductPrice'];
					unset($n[$i]->field_old_price[$n[$i]->language][0]);
					unset($n[$i]->field_saving[LANGUAGE_NONE][0]);
				}
			} else {
				watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no price',NULL,WATCHDOG_ALERT);
			}
			$n[$i]->last_import[LANGUAGE_NONE][0]['value']  = REQUEST_TIME;


			$n[$i]->revision = FALSE;
			//name + description
			$values['Descriptions'][$navi_language[$i].'_Name'] = trim($values['Descriptions'][$navi_language[$i].'_Name']);
			if ( !empty($values['Descriptions'][$navi_language[$i].'_Name'] )) {
				$n[$i]->title =  $values['Descriptions'][$navi_language[$i].'_Name'];
				//error_log ($values['Descriptions'][$navi_language[$i].'_Name']);
			} else {
				$n[$i]->title = 'no name'; // TODO default name
				//error_log ('no name');
			}

			$n[$i]->body[$n[$i]->language][0]['value'] = trim( $values['Descriptions'][$navi_language[$i].'_Description'] );

			$k = 0;
			//group
			unset($n[$i]->taxonomy_catalog[LANGUAGE_NONE]); // ET 13.03.12. kõik grupid tuleb enne uuendamist ära kustutada juhuks kui mõni neist on eemaldatud

			foreach($ProductGroups as $PG){
				if( isset( $this->DrupalTerms[ $this->DrupalVoc['group'] ][ $PG ][$n[$i]->language] ) ){
					$n[$i]->taxonomy_catalog[LANGUAGE_NONE][$k]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['group'] ][ $PG ][$n[$i]->language];
					$k++;
				} else {
					watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching group ('.$PG.')',NULL,WATCHDOG_ALERT);
				}
			}
			$k = 0;
			//Priority
			if (!empty($ProductSale)) {
				foreach($ProductSale as $PS){
					$n[$i]->priority[LANGUAGE_NONE][$k]['value'] = $PS;
					$k++;
				}
			} else {
				unset($n[$i]->priority[LANGUAGE_NONE][0]);
			}

			//color
			$values['Color1'] = (int) $values['Color1'];
			$values['Color2'] = (int) $values['Color2'];
			$check_color = array();

			if(!empty($values['Color1']) ){
				if( isset( $this->DrupalTerms[ $this->DrupalVoc['color'] ][ $values['Color1'] ][$n[$i]->language] ) ){
					$n[$i]->navi_art_colors[LANGUAGE_NONE][0]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['color'] ][$values['Color1'] ][$n[$i]->language];
					$check_color[] = $values['Color1'];
				} else {
					watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching color 1 ('.$values['Color1'].') in lang '.$n[$i]->language,NULL,WATCHDOG_ALERT);
				}
			} else {
				unset($n[$i]->navi_art_colors[LANGUAGE_NONE][0]);
			}

			if(!empty($values['Color2']) ){
				if( !in_array($values['Color2'], $check_color)) {
					if( isset( $this->DrupalTerms[ $this->DrupalVoc['color'] ][ $values['Color2'] ][$n[$i]->language] ) ){
						$n[$i]->navi_art_colors[LANGUAGE_NONE][1]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['color'] ][$values['Color2'] ][$n[$i]->language];
						$check_color[] = $values['Color2'];
					} else {
						watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching color 2 ('.$values['Color2'].') in lang '.$n[$i]->language,NULL,WATCHDOG_ALERT);
					}
				}
			} else {
				unset($n[$i]->navi_art_colors[LANGUAGE_NONE][1]);
			}


			//brand
			if( isset( $this->DrupalTerms[ $this->DrupalVoc['brand'] ][ $values['Brand'] ] ) ){
				$n[$i]->field_manufacturer[$n[$i]->language][0]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['brand'] ][ $values['Brand'] ];
			} else {
				unset($n[$i]->field_manufacturer[LANGUAGE_NONE][0]);
				watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching brand ('.$values['Brand'].')',NULL,WATCHDOG_ALERT);
			}


			if( $n[$i]->type == 'footwear' ){
				//gender
				if( isset( $this->DrupalTerms[ $this->DrupalVoc['gender'] ][ $values['CategoryCode'] ][$n[$i]->language] ) ){
					$n[$i]->navi_art_gender[LANGUAGE_NONE][0]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['gender'] ][ $values['CategoryCode'] ][$n[$i]->language];
				} else {
					unset($n[$i]->navi_art_gender[LANGUAGE_NONE][0]);
					watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching gender ('.$values['CategoryCode'].') in lang '.$n[$i]->language,NULL,WATCHDOG_ALERT);
				}

				//TopMaterial
				if( isset( $this->DrupalTerms[ $this->DrupalVoc['material'] ][ $values['TopMaterial'] ] ) ){
					$n[$i]->navi_art_top_material[LANGUAGE_NONE][0]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['material'] ][ $values['TopMaterial'] ];
				} else {
					watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching top material ('.$values['TopMaterial'].')',NULL,WATCHDOG_ALERT);
				}

				//LiningMaterial
				if( isset( $this->DrupalTerms[ $this->DrupalVoc['material'] ][ $values['LiningMaterial'] ] ) ){
					$n[$i]->navi_art_lining_material[LANGUAGE_NONE][0]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['material'] ][ $values['LiningMaterial'] ];
				} else {
					watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching lining material ('.$values['LiningMaterial'].')',NULL,WATCHDOG_ALERT);
				}

				//SoleMaterial
				if( isset( $this->DrupalTerms[ $this->DrupalVoc['material'] ][ $values['SoleMaterial'] ] ) ){
					$n[$i]->navi_art_sole_material[LANGUAGE_NONE][0]['tid'] = $this->DrupalTerms[ $this->DrupalVoc['material'] ][ $values['SoleMaterial'] ];
				} else {
					watchdog('navi_sync','Product ' .$values['Descriptions'][$navi_language[$i].'_Name']. ' ('.$values['ItemNo'].') has no matching sole material ('.$values['SoleMaterial'].')',NULL,WATCHDOG_ALERT);
				}

				$quantity_sum = $oid = 0;
				$product_attributes[$i] = uc_product_get_attributes($n[$i]->nid);
				//error_log (print_r($product_attributes[$i][$size_attribues_id]->options , TRUE));
				if (isset ($product_attributes[$i][$size_attribues_id]->label) ) {
					//size label
					$product_attributes[$i][$size_attribues_id]->label = t('Size', array() ,array('langcode' => $n[$i]->language));
					// sizes => options
					foreach($stock_size as $size => $quantity){
						//error_log ($size);
						//error_log ($quantity);
						$is_there = 0;
						foreach($product_attributes[$i][$size_attribues_id]->options as $key => $drupal_option){
							if ($drupal_option->name == $size ) {
								$is_there = 1;
								$oid = $drupal_option->oid;
								$drupal_option->keep = 1;
								//error_log ($size);
								break;
							}
						}
						//no option => add/insert

						if (!$is_there) {
							$class_has_option = 0;
							foreach($class_attributes->options as $key => $option){

								if ($option->name == $size ) {
									$option->keep = 1;
									$product_attributes[$i][$size_attribues_id]->options[] = $option;
									$oid = $option->oid;
									$class_has_option = 1;
									//error_log ('Added new option to product');
									break;
								}
							}
							//insert new option to the class
							if (!$class_has_option) {
								$new_option = new stdClass();
								$new_option->aid = $size_attribues_id;
								$new_option->name = $size;
								uc_attribute_option_save($new_option);
								uc_attribute_subject_option_save($new_option, 'class', 'footwear');
								//error_log ('Insert new option '. $size. ' to class');
								//load class attributes again
								$class_attributes = uc_attribute_load($size_attribues_id, 'footwear', 'class');
								foreach($class_attributes->options as $key => $option){
									if ($option->name == $size ) {
										$option->keep = 1;
										$product_attributes[$i][$size_attribues_id]->options[] = $option;
										$oid = $option->oid;
										//error_log ('Insert new option to class and added it to product');
										break;
									}
								}
							}
						}
						//new model number!
						//array serialize is taken from uc_attribute.admin.inc rows 1265-1269
						$size_model = $values['ItemNo'].'_'.$size;  //add size name to model
						$comb_array = array();
						$num_prod_attr = 1;
						for ($j = 1; $j <= $num_prod_attr; ++$j) {
						  $comb_array[$size_attribues_id] = $oid;
						}
						ksort($comb_array);
						db_merge('uc_product_adjustments')
						->key(array(
						  'nid' => $n[$i]->nid,
						  'combination' => serialize($comb_array),
						))
						->fields(array(
						  'model' => $size_model,
						))
						->execute();

						$quantity_sum += $quantity;
						if ($i == 0) {
							//save size stock
							db_merge('uc_product_stock')
								->key(array('sku' => $size_model))
								->fields(array(
									'nid'	=> $n[$i]->nid,
									'active' => 1,
									'stock' => $quantity,
									'threshold' => 0,
								))->execute();
						}
					}
					$option_count = 0;
					foreach($product_attributes[$i][$size_attribues_id]->options as $key => $drupal_option){
						if (!@$drupal_option->keep ) {
							$re_de = uc_attribute_subject_option_delete($drupal_option->oid, 'product', $n[$i]->nid, TRUE);
							unset($product_attributes[$i][$size_attribues_id]->options[$key]);
							//error_log ('Removing: '.$drupal_option->oid.' '.$n[$i]->nid.' '.$re_de);

						} else {
							$option_count++;
						}
					}

					$n[$i]->stock = $quantity_sum; //all sizes together
					if ($n[$i]->stock > 0 AND $n[$i]->sell_price > 0){
						$n[$i]->status = 1;
					} else {
						$n[$i]->status = 0;
					}
					if ($i == 0) {
						//save stock
						db_merge('uc_product_stock')
							->key(array('sku' => $n[$i]->model))
							->fields(array(
								'nid'	=> $n[$i]->nid,
								'active' => 1,
								'stock' => $n[$i]->stock,
								'threshold' => 0,
							))->execute();
					}
					// if only one size, make it not required and default that one option
					if ($option_count < 2 && $oid > 0) {
						//error_log (print_r($product_attributes[$i][$size_attribues_id] , TRUE));
						$product_attributes[$i][$size_attribues_id]->required = 0;
						$product_attributes[$i][$size_attribues_id]->default_option = $oid;
					} else {
						$product_attributes[$i][$size_attribues_id]->required = 1;
						$product_attributes[$i][$size_attribues_id]->default_option = 0;
					}
					// save all
					$save = uc_attribute_subject_save($product_attributes[$i][$size_attribues_id], 'product', $n[$i]->nid, TRUE);

					//salvestab üldised artibuudi andmed
					//$save = uc_attribute_save($product_attributes[$i][$size_attribues_id]);
					//error_log ($save);
				}
			} else { ////////////////////////////////////////////////////accessory///////////////

				if(isset($stock_size[0]) ) {
					$n[$i]->stock = $stock_size[0];
				} else {
					$n[$i]->stock = 0;
				}
				if ($n[$i]->stock > 0 AND $n[$i]->sell_price > 0){
					$n[$i]->status = 1;
				} else {
					$n[$i]->status = 0;
				}
				if ($i == 0) {
					//save stock
					db_merge('uc_product_stock')
						->key(array('sku' => $n[$i]->model))
						->fields(array(
							'nid'	=> $n[$i]->nid,
							'active' => 1,
							'stock' => $n[$i]->stock,
							'threshold' => 0,
						))->execute();
				}
			}
		} // langs

		//////////////////////////////////////////////////////// imgs ////////////////////////////
		if( !isset ($values['Action']['Action']) OR ( $values['Action']['Action'] == 'I' OR $values['Action']['Action'] == 'P') ) {

			$has_pics = 0;
			$full_path = realpath(".");
			for($j=1;$j<9; $j++){
				$file_name = $this->_formatFile ($n[0]->model, $j, null, null, TRUE );
				$file_path = trim($this->conf['picture']['category'] . $file_name[0]);
				$file_path1 = trim($this->conf['picture']['category'] . $file_name[1]);

				//error_log($file_path);

				$ch = curl_init();
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_NOBODY, true); // Don't download the body content
				curl_setopt($ch, CURLOPT_URL, $file_path);

				$output = curl_exec($ch);
				$info = curl_getinfo($ch);
				$found = 0;

				if ($output === false || $info['http_code'] != 200) {
					if($info['http_code'] != 404) {
						$output = "No cURL data returned for $file_path [". $info['http_code']. "]";
						if (curl_error($ch)) $output .= "\n". curl_error($ch);
						//error_log ($output);
						watchdog('navi_sync',$output,NULL,WATCHDOG_ALERT);

					}
				} else {

					$remote_size = $info['download_content_length'];
					$local_size = 0;
					$local_file = $full_path.'/sites/default/files/'.$file_name[0];
					if (is_file ( $local_file )) {
						$local_size = filesize($local_file);
					}
					//$local_size = 0; // force update!
					if ($remote_size != $local_size OR !isset($n[0]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'])) {
						$chd = curl_init();
						curl_setopt($chd, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($chd, CURLOPT_FOLLOWLOCATION, true);
						curl_setopt($chd, CURLOPT_URL, $file_path);
						$outputd = curl_exec($chd);
						$file = file_save_data($outputd, 'public://'.$file_name[0], FILE_EXISTS_REPLACE);
						for($i=0;$i<$language_count; $i++){
							$n[$i]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'] = $file->fid;
						}
						image_path_flush($file->uri);
						curl_close($chd); // Close the connection
					}
					$has_pics = 1;
					$found = 1;
				}
				curl_close($ch); // Close the connection
				if($found == 0) {
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch, CURLOPT_NOBODY, true); // Don't download the body content
					curl_setopt($ch, CURLOPT_URL, $file_path1);

					$output = curl_exec($ch);
					$info = curl_getinfo($ch);

					if ($output === false || $info['http_code'] != 200) {
						if($info['http_code'] != 404) {
							$output = "No cURL data returned for $file_path1 [". $info['http_code']. "]";
							if (curl_error($ch)) $output .= "\n". curl_error($ch);
							//error_log ($output);
							watchdog('navi_sync',$output,NULL,WATCHDOG_ALERT);
						}
					} else {
						$remote_size = $info['download_content_length'];
						$local_size = 0;
						$local_file = $full_path.'/sites/default/files/'.$file_name[1];
						if (is_file ( $local_file )) {
							$local_size = filesize( $local_file );
						}
						//$local_size = 0; // force update!
						if ($remote_size != $local_size OR !isset($n[0]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'])) {
							$chd = curl_init();
							curl_setopt($chd, CURLOPT_RETURNTRANSFER, true);
							curl_setopt($chd, CURLOPT_FOLLOWLOCATION, true);
							curl_setopt($chd, CURLOPT_URL, $file_path1);
							$outputd = curl_exec($chd);
							$file = file_save_data($outputd, 'public://'.$file_name[1], FILE_EXISTS_REPLACE);
							for($i=0;$i<$language_count; $i++){
								$n[$i]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'] = $file->fid;
							}
							image_path_flush($file->uri);
							curl_close($chd); // Close the connection
						}
						$has_pics = 1;
						$found = 1;
					}
					curl_close($ch); // Close the connection
				}

				if($found == 0) {
					if (@isset($n[0]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'])) {
						if ($n[0]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'] == 65) {

						} else {
							$file_to_delete[] = $n[0]->uc_product_image[LANGUAGE_NONE][$j-1]['fid']; // mark this file for deleting
							for($i=0;$i<$language_count; $i++){
								unset($n[$i]->uc_product_image[LANGUAGE_NONE][$j-1]);
							}
						}
					}
					if ($j == 1) {
						for($i=0;$i<$language_count; $i++){
							$n[$i]->uc_product_image[LANGUAGE_NONE][$j-1]['fid'] = 65;
						}
					}
				}
			}
			//slideshow true/false
			for($i=0;$i<$language_count; $i++){
				if ($has_pics == 1 AND $has_DiscountedPrice)  {
					$n[$i]->field_slideshow[$n[$i]->language][0]['value'] = 1;
				} else {
					$n[$i]->field_slideshow[$n[$i]->language][0]['value'] = 0;
				}
			}
		}

		try{
			//save node(s)
			for($i=0;$i<$language_count; $i++){
				if ($i == 0) {
					//if (!empty($n[$i]->nid)) {
						$n[$i]->tnid = $n[0]->nid;
						node_save($n[$i]);
					//} else {
					//	node_save($n[$i]); // no nid, we have to save node before!
					//	$n[$i]->tnid = $n[$i]->nid;
					//	node_save($n[$i]);
					//}
				} else {
					//translations
					$n[$i]->tnid = $n[0]->nid;
					$n[$i]->translate = 0;
					node_save($n[$i]);
				}

			}
			if (isset($file_to_delete[0])) {
				foreach($file_to_delete as $fid){
					$file_object = file_load($fid);
					if (is_object($file_object) ) {
						file_delete($file_object, FALSE); // delete file, if it's not used any more.
					} else {
						$imgpath = file_create_url($file_object->uri);
						watchdog('navi_sync','ERROR deleting FILE fid: '.$fid.' path: '.$imgpath,NULL,WATCHDOG_ALERT);
					}
				}
			}
			if ($is_new == 1) {
				watchdog('navi_sync','Insert new product nid = '.$n[0]->nid.' : <a href="'.url('node/'.$n[0]->nid).'">'.$n[0]->title.'</a>' ,NULL,WATCHDOG_DEBUG);
			} else {
				watchdog('navi_sync','Update product nid = '.$n[0]->nid.' : <a href="'.url('node/'.$n[0]->nid).'">'.$n[0]->title.'</a>' ,NULL,WATCHDOG_DEBUG);
			}
			return TRUE;

		}catch(Exception $e){
			watchdog('navi_sync','ERROR updating NODE : '.$n[$i]->nid.' - '.$e->getMessage(),NULL,WATCHDOG_ALERT);
			return FALSE;
		}


	}

	/**
     * @param int $imageNumber
     * @return string
     */
   protected function _formatFile ( $sku, $imageNumber, $width = null, $height = null, $includeExt = true ) {
		$file_name =  array();
		if ( null !== $width and null !== $height )
			 $sizePart = '_' . $width . '_' . $height;
		else
			 $sizePart = '';

		if ( $includeExt )
			 $extPart = '.' . $this->conf['picture']['extension'];
		else
			 $extPart = '';

		$file_name[] = $sku . '-' . $imageNumber . $sizePart . strtolower($extPart);
		$file_name[] = $sku . '-' . $imageNumber . $sizePart . strtoupper($extPart);
		return $file_name;
   }

	public function getProductPictures ( $productSku ) {
		$pics = array();
		$full_path = realpath(".");
		//error_log(print_r($full_path,1));
		for($j=1;$j<9; $j++){
			$file_name = $this->_formatFile ($productSku, $j, null, null, TRUE );
			$file_path = trim($this->conf['picture']['category'] . $file_name[0]);
			$file_path1 = trim($this->conf['picture']['category'] . $file_name[1]);
			//error_log(is_file ( $file_path ));
			//error_log(is_file ( $file_path1 ));


			$ch = curl_init();
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_NOBODY, true); // Don't download the body content
			curl_setopt($ch, CURLOPT_URL, $file_path);


			$output = curl_exec($ch);
			$info = curl_getinfo($ch);
			$found = 0;

			if ($output === false || $info['http_code'] != 200) {
				if($info['http_code'] != 404) {
					$output = "No cURL data returned for $file_path [". $info['http_code']. "]";
					if (curl_error($ch)) $output .= "\n". curl_error($ch);
					error_log ($output);
				}
			} else {
				$remote_size = $info['download_content_length'];
				$local_file = $full_path.'/sites/default/files/'.$file_name[0];
				$update = '';
				if (is_file ( $local_file )) {
					$local_size = filesize($local_file);
					if ($remote_size == $local_size) {
						$update = ' (no update)';
					}
				}
				$pics[] = $file_path.$update;
				$found = 1;
			}

			if($found == 0) {
				curl_setopt($ch, CURLOPT_URL, $file_path1);

				$output = curl_exec($ch);
				$info = curl_getinfo($ch);

				if ($output === false || $info['http_code'] != 200) {
					if($info['http_code'] != 404) {
						$output = "No cURL data returned for $file_path1 [". $info['http_code']. "]";
						if (curl_error($ch)) $output .= "\n". curl_error($ch);
						error_log ($output);
					}
				} else {
					$remote_size = $info['download_content_length'];
					$local_file = $full_path.'/sites/default/files/'.$file_name[1];
					$update = '';
					if (is_file ( $local_file )) {
						$local_size = filesize($local_file);
						if ($remote_size == $local_size) {
							$update = ' (no update)';
						}
					}
					$pics[] = $file_path1.$update;
					$found = 1;
				}
			}
			curl_close($ch); // Close the connection
			//if( is_file ( $file_path ))  {
			//	$pics[] = $file_path;
			//} elseif (is_file ( $file_path1 )) {
			//	$pics[] = $file_path1;
			//}
		}
		return $pics;
	}

}

	 