<?php

namespace App\Repositories;

use App\Traits\ArrayTrait;
use App\Traits\NumericTrait;
use App\Model\Entity\UserProfile;
use Cake\ORM\Locator\LocatorAwareTrait;
use Alus\Catalogs\Model\Entity\Product;
use App\Services\Traits\ServiceAwareTrait;
use App\Interfaces\Repositories\OrderInterface;
use App\Services\Request\Saas\PurchaseOrderSaasService;

class OrderRepository implements OrderInterface
{

    use LocatorAwareTrait, ServiceAwareTrait, NumericTrait, ArrayTrait;

    private $Model;
    private $products = [];

    public function __construct($config = null)
    {
        $this->Model = $this->getSaasService("PurchaseOrder", $config);
    }

    public function setProduct($productId, $quantity = 1)
    {
        foreach ($this->products as &$product) {
            if ($product['product_id'] == $productId) {
                $product['product_quantity'] += $quantity;
                return;
            }
        }

        $this->products[] = [
            'product_id'       => $productId,
            'product_quantity' => $quantity
        ];
    }

    public function create($product, $userProfle, array $address)
    {
        $productType = "PHYSICAL";

        if ($product->type != 'PHYSICAL_PRODUCT') {
            $productType = "DIGITAL";
        }

        $order = (object) $this->Model->create([
            'client'                => $this->Model->getCompany()->code,
            'project'               => $this->Model->getCompany()->project,
            'buyer_reference'       => $userProfle->getBuyerRef(),
            'contact_name'          => $userProfle->first_names,
            'contact_cellphone'     => empty($userProfle->cellphone) ? '0000000000' : $userProfle->cellphone,
            'contact_landphone'     => empty($userProfle->cellphone) ? '0000000000' : $userProfle->cellphone,
            'contact_email'         => $userProfle->email,
            'product_part_number'   => $product->part_number,
            'product_vendor'        => $product->vendor->name,
            'product_category'      => $product->category->name,
            'product_sub_category'  => $product->category->child->name,
            'product_name'          => $product->name,
            'product_description'   => $product->desc,
            'product_quantity'      => 1,
            'convertion_factor'     => $this->Model->getCompany()->conversion_factor,
            'operation'             => $this->Model->getCompany()->operation,
            'product_price'         => $product->price,
            'product_points_value'  => $product->points_value,
            'product_type'          => $productType,
            'address_country'       => 'México',
            'address_state'         => $address['state'],
            'address_city'          => $address['city'],
            'address_district'      => 'NA',
            'address_municipality'  => 'NA',
            'address_colony'        => $address['colony'],
            'address_street'        => $address['street'],
            'address_ext_number'    => $address['external_number'],
            'address_int_number'    => $address['internal_number'],
            'address_postal_code'   => $address['postal_code'],
            'address_references'    => 'NA',
            'address_lat'           => 19.4377344,
            'address_lng'           => -99.1476314,
        ]);

        return $this->formattedOrder($order);
    }

    public function createOrder($userProfile, array $address, $productId = null)
    {
        if ($productId != null){
            $product[] = [
                'product_id'       => $productId,
                'product_quantity' => 1
            ];
        }
        $payload = [
            'client'          => $this->Model->getCompany()->code,
            'project'         => $this->Model->getCompany()->project,
            'buyer_reference' => $userProfile->getBuyerRef(),
            'contact_name'    => $userProfile->first_names,
            'contact_cellphone' => empty($userProfile->cellphone) ? '0000000000' : $userProfile->cellphone,
            'contact_landphone' => empty($userProfile->cellphone) ? '0000000000' : $userProfile->cellphone,
            'contact_email'   => $userProfile->email,

            'address_country'      => $address['country']     ?? 'México',
            'address_state'        => $address['state']       ?? 'NA',
            'address_city'         => $address['city']        ?? 'NA',
            'address_district'     => $address['district']    ?? 'NA',
            'address_municipality' => $address['municipality'] ?? 'NA',
            'address_colony'       => $address['colony']      ?? '',
            'address_street'       => $address['street']      ?? '',
            'address_ext_number'   => $address['external_number'] ?? '',
            'address_int_number'   => $address['internal_number'] ?? '',
            'address_postal_code'  => $address['postal_code'] ?? '',
            'address_references'   => 'NA',
            'address_lat'          => 19.4377344,
            'address_lng'          => -99.1476314,

            'operation' => $this->Model->getCompany()->operation,

            'products'  => $product ?? json_encode($this->products),
        ];

        $result = (object) $this->Model->create($payload);

        $this->products = [];
        $product = [];

        return $this->formattedOrder($result);
    }


    public function getById($id)
    {
        return $this->Model->getById($id);
    }

    public function getByBuyerReference(string $buyerReference)
    {
        return $this->Model->getByBuyerReference($buyerReference);
    }

    public function getAll()
    {
        return $this->Model->getAll();
    }

    public function paginated($params)
    {
        $orderParams = [];

        if (isset($params["page"])) {
            $orderParams["page"] = $params["page"];
        }

        if (isset($params["limit"])) {
            $orderParams["limit"] = $params["limit"];
        }

        if (!empty($params["search"])) {
            $orderParams["search"] = $params["search"];
        }

        if (!empty($params["sort_element"])) {
            $orderParams["sort_element"] = $params["sort_element"];

            if (!empty($params["sort_direction"])) {
                $orderParams["sort_direction"] = $params["sort_direction"];
            }
        }

        // dd(http_build_query($orderParams));
        return $this->Model->paginated(http_build_query($orderParams));
    }

    public function getReport($status = null)
    {
        if (!empty($status)) {
            $status = mb_strtoupper($status);

            if (in_array($status, PurchaseOrderSaasService::ORDER_STATUS)) {
                switch ($status) {
                    case PurchaseOrderSaasService::ORDER_CANCELED_STATUS;
                        $status = "cancel";
                        break;
                    case PurchaseOrderSaasService::ORDER_TRANSIT_STATUS;
                        $status = "sent";
                        break;
                }
                return $this->Model->getReport(mb_strtolower($status));
            }
        }

        return $this->Model->getReport();
    }

    public function batchUpdate(String $filePath)
    {
        $payload = [
            "success" => true,
            "errors"  => []
        ];

        try {
            $this->Model->batchUpdate($filePath);
        } catch (\Throwable $th) {
            $failureReason = explode(" :: ", $th->getMessage());

            $payload["errors"]  = ['Ocurrió un error inesperado al cargar el archivo. Por favor, comuníquese con el administrador de la plataforma'];
            $payload["success"] = false;

            if (count($failureReason) == 2) {
                $failureReason = json_decode($failureReason[1]);

                if (!empty($failureReason->errors)) {
                    $payload["errors"] = $failureReason->errors;
                }
            }
        }

        return $payload;
    }

    public function cancelByID($id)
    {
        return $this->Model->cancelByID($id);
    }

    private function formattedOrder($orderData)
    {
        $orders = [];
        // Procesamos cada orden individualmente
        foreach ($orderData as $key => $order) {
            $formatted = (object)[
                'id' => $this->serialNumber($order['id']),
                'address' => $this->compactAddress(json_decode(json_encode($order))),
                'product_name' => $order['product_name'],  // Agregamos product_name que necesitamos para emails
                'products' => [
                    (object)[
                        'product_id' => $order['product_id'] ?? null,
                        'product_quantity' => $order['product_quantity'] ?? 1,
                        'product_points_value' => $this->priceFormat($order['product_points_value'] ?? 0),
                    ]
                ]
            ];
    
            $orders[] = $formatted;
        }
        return $orders;
    }

    private function compactAddress($order)
    {
        $address = "";

        // Calle
        if (!empty($order->address_street)) {
            $address .= $order->address_street . ', ';
        }
        // Número exterior
        if (!empty($order->address_ext_number)) {
            $address .= 'Exterior ' . $order->address_ext_number . ', ';
        }
        // Numero interior
        if (!empty($order->address_int_number)) {
            $address .= 'Interior ' . $order->address_int_number . ', ';
        }
        // Colonia
        if (!empty($order->address_colony)) {
            $address .= $order->address_colony . ', ';
        }
        // Codigo Postal
        if (!empty($order->address_postal_code)) {
            $address .= 'CP ' . $order->address_postal_code . ', ';
        }
        // Estado
        if (!empty($order->address_state)) {
            $address .= $order->address_state;
        }

        return $address;
    }

    public function getTotalRedeemedPoints()
    {
        $dates = [
            "end"   => date('Y-m-t', strtotime('last month')),
            "start" => date('Y-m-01', strtotime('last month')),
        ];
        $totals = [
            "totalPesos"  => 0,
            "totalPoints" => 0,
        ];

        foreach ($this->Model->getAll() as $order) {
            if ($order["created_at"] >= $dates['start'] && $order["status"] != PurchaseOrderSaasService::ORDER_CANCELED_STATUS) {
                $totals["totalPesos"]  += $order["product_price"];
                $totals["totalPoints"] += $order["product_points_value"];
            }
        }

        $periodMessage = "Los datos de la tabla de OC son del mes de " . date('M Y', strtotime('last month'));

        return [
            "message"     => $periodMessage,
            "totalPesos"  => number_format($totals["totalPesos"], 0, '.', ','),
            "totalPoints" => number_format($totals["totalPoints"], 0, '.', ','),
        ];
    }
}
