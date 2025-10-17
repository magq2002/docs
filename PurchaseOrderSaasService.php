<?php

namespace App\Services\Request\Saas;

use App\Services\Request\HttpClientService;

class PurchaseOrderSaasService extends HttpClientService{

    const ORDER_SENT_STATUS      = "SENT";
    const ORDER_PENDING_STATUS   = "PENDING";
    const ORDER_CANCELED_STATUS  = "CANCELED";
    const ORDER_DELIVERED_STATUS = "DELIVERED";
    // Estatus dentro de la plataforma
    const ORDER_TRANSIT_STATUS = "TRANSIT";

    const ORDER_STATUS = [
        self::ORDER_SENT_STATUS,
        self::ORDER_PENDING_STATUS,
        self::ORDER_CANCELED_STATUS,
        self::ORDER_DELIVERED_STATUS,
        // Estatus dentro de la plataforma
        self::ORDER_TRANSIT_STATUS
    ];

    public function __construct($companyCode = null){
        parent::__construct($companyCode, self::PURCHASE_ORDER_TYPE);
    }

    public function getAll() {
        return $this->secured()->get("");
    }

    public function getStatistics() {
        return $this->secured()->get("statistics/");
    }

    public function getById($id) {
        return $this->secured()->get("{$id}/");
    }

    public function getByBuyerReference($buyerReference) {
        return $this->secured()->get("{$buyerReference}/");
    }

    public function cancelByID($id) {
        return $this->secured()->patch("{$id}/cancel/");
    }

    public function deleteByID($id) {
        return $this->secured()->delete("{$id}/");
    }

    public function paginated($params = ""){
        return $this->secured()->get("?{$params}");
    }

    public function getReport($status = null) {
        $basePath = "report/";

        if( !empty($status) ){
            $basePath .= "{$status}/";
        }

        return $this->secured()->get($basePath, [], false);
    }

    public function create($po) {
        return $this->secured()->post("", $po);
    }

    public function batchUpdate($filePath) {
        return $this->secured()->post("batch/excel/", [
            "file" => $this->getCurlFileFromPath($filePath)
        ], true);
    }

}
