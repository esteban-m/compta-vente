<?php

if (!defined('_PS_VERSION_'))
	exit;

class comptavente extends ModuleGrid
{
	private $html = null;
	private $query = null;
	private $columns = null;
	private $default_sort_column = null;
	private $default_sort_direction = null;
	private $empty_message = null;
	private $paging_message = null;

	public function __construct()
	{
		$this->name = 'comptavente';
		$this->tab = 'analytics_stats';
		$this->version = '1.0.0';
		$this->author = 'Esteban Mauvais';
		$this->need_instance = 0;

		parent::__construct();

		$this->default_sort_column = 'supplier_name';
		$this->default_sort_direction = 'DESC';
		$this->empty_message = $this->l('An empty record-set was returned.');
		$this->paging_message = sprintf($this->l('Displaying %1$s of %2$s'), '{0} - {1}', '{2}');

		$this->columns = array(
			array(
				'id' => 'supplier_name',
				'header' => $this->l('Fournisseur'),
				'dataIndex' => 'supplier_name',
				'align' => 'left'
			),
			array(
				'id' => 'name',
				'header' => $this->l('Nom'),
				'dataIndex' => 'name',
				'align' => 'left'
			),
			array(
				'id' => 'totalQuantitySold',
				'header' => $this->l('Quantité'),
				'dataIndex' => 'totalQuantitySold',
				'align' => 'center'
			),
			array(
				'id' => 'avgPriceSold',
				'header' => $this->l('Prix'),
				'dataIndex' => 'avgPriceSold',
				'align' => 'right'
			),
			array(
				'id' => 'avgPriceSoldTax',
				'header' => $this->l('Prix de vente avec TVA'),
				'dataIndex' => 'avgPriceSoldTax',
				'align' => 'right'
			),
			array(
				'id' => 'totalPriceSold',
				'header' => $this->l('Ventes'),
				'dataIndex' => 'totalPriceSold',
				'align' => 'right'
			),
			array(
				'id' => 'totalPriceSoldTax',
				'header' => $this->l('Ventes avec TVA'),
				'dataIndex' => 'totalPriceSoldTax',
				'align' => 'right'
			),
			array(
				'id' => 'tva',
				'header' => $this->l('TVA'),
				'dataIndex' => 'tva',
				'align' => 'center'
			)
		);

		$this->displayName = $this->l('Comptabilité des ventes');
		$this->description = $this->l('Plugin pour la comptabilité des ventes');
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
	}

	public function install()
	{
		return (parent::install() && $this->registerHook('AdminStatsModules'));
	}

	public function hookAdminStatsModules($params)
	{
		$engine_params = array(
			'id' => 'id_product',
			'title' => $this->displayName,
			'columns' => $this->columns,
			'defaultSortColumn' => $this->default_sort_column,
			'defaultSortDirection' => $this->default_sort_direction,
			'emptyMessage' => $this->empty_message,
			'pagingMessage' => $this->paging_message
		);

		if (Tools::getValue('export'))
			$this->csvExport($engine_params);

		return '<div class="panel-heading">'.$this->displayName.'</div>
		'.$this->engine($engine_params).'
		<a class="btn btn-default export-csv" href="'.Tools::safeOutput($_SERVER['REQUEST_URI'].'&export=1').'">
			<i class="icon-cloud-upload"></i> '.$this->l('CSV Export').'
		</a>';
	}

	public function getData()
	{
		$currency = new Currency(Configuration::get('PS_CURRENCY_DEFAULT'));
		$date_between = $this->getDate();
		$array_date_between = explode(' AND ', $date_between);

		$this->query = 'SELECT SQL_CALC_FOUND_ROWS p.reference, p.id_product, pl.name,
				s.name as supplier_name,
				ROUND(AVG(od.unit_price_tax_incl / o.conversion_rate), 2) as avgPriceSoldTax,
				ROUND(AVG(od.product_price / o.conversion_rate), 2) as avgPriceSold,
				IFNULL(stock.quantity, 0) as quantity,
				IFNULL(SUM(od.product_quantity), 0) AS totalQuantitySold,
				ROUND(IFNULL(IFNULL(SUM(od.product_quantity), 0) / (1 + LEAST(TO_DAYS('.$array_date_between[1].'), TO_DAYS(NOW())) - GREATEST(TO_DAYS('.$array_date_between[0].'), TO_DAYS(product_shop.date_add))), 0), 2) as averageQuantitySold,
				ROUND(IFNULL(SUM((od.unit_price_tax_incl * od.product_quantity) / o.conversion_rate), 0), 2) AS totalPriceSoldTax,
				ROUND(IFNULL(SUM((od.product_price * od.product_quantity) / o.conversion_rate), 0), 2) AS totalPriceSold,
				ROUND((od.unit_price_tax_incl - od.unit_price_tax_excl) * 100 / od.unit_price_tax_excl, 2) as tva,
				(
					SELECT IFNULL(SUM(pv.counter), 0)
					FROM '._DB_PREFIX_.'page pa
					LEFT JOIN '._DB_PREFIX_.'page_viewed pv ON pa.id_page = pv.id_page
					LEFT JOIN '._DB_PREFIX_.'date_range dr ON pv.id_date_range = dr.id_date_range
					WHERE pa.id_object = p.id_product AND pa.id_page_type = '.(int)Page::getPageTypeByName('product').'
					AND dr.time_start BETWEEN '.$date_between.'
					AND dr.time_end BETWEEN '.$date_between.'
				) AS totalPageViewed,
				product_shop.active
				FROM '._DB_PREFIX_.'product p
				'.Shop::addSqlAssociation('product', 'p').'
				LEFT JOIN '._DB_PREFIX_.'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = '.(int)$this->getLang().' '.Shop::addSqlRestrictionOnLang('pl').')
				LEFT JOIN '._DB_PREFIX_.'order_detail od ON od.product_id = p.id_product
				LEFT JOIN '._DB_PREFIX_.'orders o ON od.id_order = o.id_order
				LEFT JOIN '._DB_PREFIX_.'supplier s ON s.id_supplier = p.id_supplier
				'.Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o').'
				'.Product::sqlStock('p', 0).'
				WHERE o.valid = 1
				AND o.invoice_date BETWEEN '.$date_between.'
				GROUP BY od.product_id';

		if (Validate::IsName($this->_sort))
		{
			$this->query .= ' ORDER BY `'.bqSQL($this->_sort).'`';
			if (isset($this->_direction) && Validate::isSortDirection($this->_direction))
				$this->query .= ' '.$this->_direction;
		}

		if (($this->_start === 0 || Validate::IsUnsignedInt($this->_start)) && Validate::IsUnsignedInt($this->_limit))
			$this->query .= ' LIMIT '.(int)$this->_start.', '.(int)$this->_limit;

		$values = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($this->query);
		foreach ($values as &$value)
		{
			$value['avgPriceSold'] = $value['avgPriceSold'] . ' €';
			$value['totalPriceSold'] = $value['totalPriceSold'] . ' €';
			$value['avgPriceSoldTax'] = $value['avgPriceSoldTax'] . ' €';
			$value['totalPriceSoldTax'] = $value['totalPriceSoldTax'] . ' €';
			$value['tva'] = $value['tva'] . ' %';
		}
		unset($value);

		$this->_values = $values;
		$this->_totalCount = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('SELECT FOUND_ROWS()');
	}
}
