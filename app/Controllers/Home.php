<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        $g = new \jqgrid(config('GridPHP')->dbconf());

        // enable multi-selection of rows and subgrid
        $g->set_options([
            'caption' => 'CodeIgniter Datagrid',
            'multiselect' => true, 
            'subGrid' => true, 
            'subgridurl' => 'detail', 
        ]);

        // set datasource for selection and modification
        $g->table = 'customers';
        $g->select_command = 'SELECT customer_id, company_name, contact_name, contact_title, city, postal_code, country, phone FROM customers';

        // customize specific columns
        $g->set_columns([
            [
                'name' => 'country',
                'formatter' => 'badge'
            ],
            [
                'name' => 'city',
                'formatter' => 'badge'
            ],
        ],true);

        // enable export action
        $g->set_actions([
            'export' => true
        ]);

        // generate
        $data['output'] = $g->render('customers');

        return view('welcome_message',$data);
    }
    public function detail(): string
    {
        $g = new \jqgrid(config('GridPHP')->dbconf());

        $g->set_options([
            'caption' => '',
            'readonly' => true,
            'toolbar' => 'bottom',
        ]);

        $g->table = 'orders';

        // filter orders by customer id selected in master grid
        $cid = $this->request->getGet('rowid') ?? '';
        $g->select_command = "SELECT order_id, order_date, shipped_date, freight, ship_name, ship_address, ship_city, ship_country FROM orders where customer_id = '".$cid."'";

        $data['output'] = $g->render('cust_orders');

        return $data['output'];
    }    
}
