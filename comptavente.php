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
	private $option = null;
	private $_option;
	public function __construct()
	{
		$this->name = 'comptavente';
		$this->tab = 'analytics_stats';
		$this->version = '1.0.0';
		$this->author = 'Esteban Mauvais';
		$this->need_instance = 0;

		parent::__construct();

		$this->displayName = $this->l('Comptabilité des ventes');
		$this->description = $this->l('Plugin pour la comptabilité des ventes');
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
	}
	public function install()
	{
		return (parent::install() && $this->registerHook('AdminStatsModules'));
	}
	public function setOption($option, $layers = 1){
		$this->_option = $option;
	}
	public function getData() {
	}
	public function hookAdminStatsModules($params) {
		$this->default_sort_column = 'supplier_name';
		$this->default_sort_direction = 'DESC';
		$currency = new Currency(Configuration::get('PS_CURRENCY_DEFAULT'));
		$id_supplier = Tools::getValue('id_supplier');
		$date_between = $this->getDate();		
		$array_date_between = explode(' AND ', $date_between);
		$this->query = 'SELECT SQL_CALC_FOUND_ROWS pl.name,
				s.name as supplier_name,
				ROUND(AVG(od.unit_price_tax_incl / o.conversion_rate), 2) as avgPriceSoldTax,
				ROUND(AVG(od.product_price / o.conversion_rate), 2) as avgPriceSold,
				IFNULL(SUM(od.product_quantity), 0) AS totalQuantitySold,
				ROUND(IFNULL(IFNULL(SUM(od.product_quantity), 0) / (1 + LEAST(TO_DAYS('.$array_date_between[1].'), TO_DAYS(NOW())) - GREATEST(TO_DAYS('.$array_date_between[0].'), TO_DAYS(product_shop.date_add))), 0), 2) as averageQuantitySold,
				ROUND(IFNULL(SUM((od.unit_price_tax_incl * od.product_quantity) / o.conversion_rate), 0), 2) AS totalPriceSoldTax,
				ROUND(IFNULL(SUM((od.product_price * od.product_quantity) / o.conversion_rate), 0), 2) AS totalPriceSold,
				ROUND((od.unit_price_tax_incl - od.unit_price_tax_excl) * 100 / od.unit_price_tax_excl, 2) as tva,
				product_shop.active
				FROM '._DB_PREFIX_.'product p
				'.Shop::addSqlAssociation('product', 'p').'
				LEFT JOIN '._DB_PREFIX_.'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = 1 '.Shop::addSqlRestrictionOnLang('pl').')
				LEFT JOIN '._DB_PREFIX_.'order_detail od ON od.product_id = p.id_product
				LEFT JOIN '._DB_PREFIX_.'orders o ON od.id_order = o.id_order
				LEFT JOIN '._DB_PREFIX_.'supplier s ON s.id_supplier = p.id_supplier
				'.Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o').'
				'.Product::sqlStock('p', 0).'
				WHERE o.valid = 1
					'.($id_supplier ? 'AND s.id_supplier = '.(int)$id_supplier : '').'
				AND o.invoice_date BETWEEN '.$date_between.'
				GROUP BY od.product_id
				ORDER BY `'.$this->default_sort_column.'`';
		if (Validate::IsName($this->_sort))
		{
			if (isset($this->_direction) && Validate::isSortDirection($this->_direction))
				$this->query .= ' '.$this->_direction;
		}
		if (($this->_start === 0 || Validate::IsUnsignedInt($this->_start)) && Validate::IsUnsignedInt($this->_limit))
			$this->query .= ' LIMIT '.(int)$this->_start.', '.(int)$this->_limit;
		$values = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($this->query);		
		foreach ($values as &$value) {
			$value['avgPriceSold'] = $value['avgPriceSold'] . ' €';
			$value['totalPriceSold'] = $value['totalPriceSold'] . ' €';
			$value['avgPriceSoldTax'] = $value['avgPriceSoldTax'] . ' €';
			$value['totalPriceSoldTax'] = $value['totalPriceSoldTax'] . ' €';
			$value['tva'] = $value['tva'] . ' %';			
		}
		unset($value);
		$this->_values = $values;
		$this->_totalCount = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('SELECT FOUND_ROWS()');
		
		/*EXPORT CSV*/
		if (Tools::getValue('export')) {
			$handle = fopen('php://output', 'w+');
			fputcsv($handle, array(
				'Fournisseur',
				'Nom',
				'Quantité',
				'Prix',
				'Prix de vente avec TVA',
				'Ventes',
				'Ventes avec TVA',
				'TVA'
            ),';');
			foreach ($values as $value) {
				fputcsv($handle,array(
					$value['supplier_name'],
					$value['name'],
					$value['totalQuantitySold'],
					$value['avgPriceSold'],
					$value['avgPriceSoldTax'],
					$value['totalPriceSold'],
					$value['totalPriceSoldTax'],
					$value['tva']
				),';');
			}
			fclose($handle);
			header('Content-type: text/csv');
			header('Content-Type: application/force-download; charset=UTF-8');
			header('Cache-Control: no-store, no-cache');
			header('Content-Disposition: attachment; filename="'.$this->displayName.' - '.time().'.csv"');
			exit;
		}
		
		/*SELECT*/
		$suppliers = Supplier::getSuppliers((int)$this->context->language->id, true, false);
		$str = '<form action="#" method="post" id="suppliersForm" class="form-horizontal">
					<div class="row row-margin-bottom">
						<label class="control-label col-lg-3">
							<span title="" data-toggle="tooltip" class="label-tooltip" data-original-title="' . $this->l('Click on a product to access its statistics!') . '">' . $this->l('Choose a supplier') . '</span>
						</label>
					<div class="col-lg-3">
					<select name="id_supplier" onchange="$(\'#suppliersForm\').submit();">
					<option value="0">' . $this->l('All') . '</option>';
		foreach($suppliers as $supplier) {
			$str.= '<option value="' . $supplier['id_supplier'] . '"' . ($id_supplier == $supplier['id_supplier'] ? ' selected="selected"' : '') . '>' . $supplier['name'] . '</option>';
		}
		$str.= '	</select>
				</div>
			</div>
		</form>';
		
		$str.= '<table class="table" id="grid_1">
			<thead>
				<tr><th class="center"><span class="title_box active">Fournisseur</span></th><th class="center"><span class="title_box active">Nom</span></th><th class="center"><span class="title_box active">Quantité</span></th><th class="center"><span class="title_box active">Prix</span></th><th class="center"><span class="title_box active">Prix de vente avec TVA</span></th><th class="center"><span class="title_box active">Ventes</span></th><th class="center"><span class="title_box active">Ventes avec TVA</span></th><th class="center"><span class="title_box active">TVA</span></th></tr>
			</thead>
			<tbody>';
		
		/*GRID*/
		$i = 0;
		foreach ($values as &$value) {
			$str.= 
			'<tr>
				<td align="left">'.$value['supplier_name'].'</td>
				<td align="left">'.$value['name'].'</td>
				<td align="center">'.$value['totalQuantitySold'].'</td>
				<td align="right">'.$value['avgPriceSold'].'</td>
				<td align="right">'.$value['avgPriceSoldTax'].'</td>
				<td align="right">'.$value['totalPriceSold'].'</td>
				<td align="right">'.$value['totalPriceSoldTax'].'</td>
				<td align="center">'.$value['tva'].'</td>
			</tr>';
			$i++;
		}
		if ($i > 1) {
			$i = $i . ' articles';
		} else {
			$i = $i . ' article';
		}
		$str.='
		</tbody>
			<tfoot>
				<tr>
					<th colspan="8">Affichage de '.$i.'.</th>
				</tr>
			</tfoot>
		</table>';
		
		/*BUTTON*/
		$str.= 
		'<a class="btn btn-default export-csv" href="'
		. Tools::safeOutput($_SERVER['REQUEST_URI'] . '&export=1&id_supplier=' . $id_supplier) . '">
			<i class="icon-cloud-upload"></i> ' . $this->l('CSV Export') . 
		'</a>';
		return $str;
	}
}
