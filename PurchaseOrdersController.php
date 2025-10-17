<?php

namespace App\Controller\API\V2\Collaborator;

use Cake\I18n\Number;
use Cake\Mailer\Email;
use Cake\Http\Exception;
use Cake\ORM\TableRegistry;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;

class PurchaseOrdersController extends CollaboratorController
{

    public function initialize()
    {
        parent::initialize();

        $this->loadComponent(
            'Alus/Catalogs.Catalog',
            [
                'catalog' => 'incentive_mx',
                'pricing' => [
                    'conversionFactor' => $this->company->conversion_factor,
                    'round' => [
                        'method' => 'ceil'
                    ]
                ]
            ]
        );

        $this->loadModel('ShoppingCarts');
        $this->loadModel('CertificateDocuments');
        $this->Catalog->setCatalogByTag('incentive_mx');

        $this->Auth->allow(['test']);
    }

    /*** - ADAPTADO CON SAAS - Record a Single Product Purchase Order ***/
    public function create()
    {
        // throw new InternalErrorException("Los canjes se encuentran deshabilitados por el momento, se reanudara en las proximas horas");
        $this->request->allowMethod('POST');

        $order        = (object)[];
        $profile      = (object)[];
        $productID    = $this->request->getData('product_id');
        $currentUser  = $this->getRepository("User")->getCurrentUser($this->Auth->user()['id']);

        if (empty($productID)) {
            throw new Exception\ForbiddenException('No se especificó el producto a redimir');
        }

        if (empty($currentUser)) {
            throw new Exception\ForbiddenException('Usuario no autentificado.');
        }

        ConnectionManager::get('default')->transactional(function ($conn) use ($productID, &$order, &$profile, $currentUser) {
            try {
                $locator  = TableRegistry::getTableLocator();
                $Address  = $locator->get('UserAddress');
                $Profiles = $locator->get('UserProfile');

                $product = json_decode(json_encode($this->getRepository("Catalog")->products()->getProductByID($productID, false)));
                $profile = $Profiles->find("byUserID", ["userID" => $currentUser->id])->epilog('FOR UPDATE')->first();
                $address = $Address->find("byUserID", ["userID" => $currentUser->id])->find("compactAddress")->disableHydration()->first();

                if (empty($address)) {
                    throw new Exception\ForbiddenException('Aún no registra una dirección de entrega. El canje no puede ser procesado. ' . $currentUser->id);
                }

                if (empty($product)) {
                    throw new Exception\ForbiddenException('El producto no se encuentra disponible');
                }

                $available = $profile->accumulated_points - $profile->redeemed_points;

                // Verificar que tenga puntos disponibles
                if ($product->points_value > $available) {
                    throw new Exception\ForbiddenException('No dispone de saldo suficientes para realizar la redención');
                }

                $order = $this->getRepository("Order")->createOrder($profile, $address, $product->id);

                $profile->redeemed_points += $product->points_value;

                $Profiles->save($profile);

                if (!empty($profile->email)) {
                    $this->getMailer("PurchaseOrder")->new($currentUser, $order);
                }
            } catch (\Throwable $th) {
                // Si existe la orden cancelarla
                foreach ($order as $key => $valOrder) {
                    foreach ($valOrder as $singleOrder) {
                        if (!empty($order)) {
                            $this->getRepository("Order")->cancelByID($singleOrder->id);
                        }
                    }
                }

                throw $th;
            }
        });

        $this->set([
            'success'   => true,
            'available' => $profile->accumulated_points - $profile->redeemed_points
        ]);
    }

    /*** - ADAPTADO CON SAAS - Redeem for shopping cart ***/
    public function purchaseShoppingCart()
    {
        // throw new InternalErrorException("Los canjes se encuentran deshabilitados por el momento, se reanudara en las proximas horas");
        $this->request->allowMethod('POST');

        $currentUser  = $this->getRepository("User")->getCurrentUser($this->Auth->user('id'));
        $userRepository = $this->getRepository("User");
        $shoppingCart   = $userRepository->shoppingCart()->findOrCreate($this->Auth->user('id'));
        $cartItems      = json_decode(json_encode($userRepository->shoppingCart()->items()->getFormatted($shoppingCart, false)));

        if (empty($cartItems->cart)) {
            throw new Exception\ForbiddenException('Carrito vacío.');
        }

        if (empty($currentUser)) {
            throw new Exception\ForbiddenException('Usuario no autentificado.');
        }

        $data    = $cartItems->cart;
        $conn    = ConnectionManager::get('default');
        $orders  = [];
        $profile = (object)[];

        $conn->transactional(function ($conn) use ($data, &$profile, &$orders, $currentUser, $userRepository, $shoppingCart) {
            try {
                $User       = $this->Auth->user();
                $locator    = TableRegistry::getTableLocator();
                $Catalog    = $this->Catalog;
                $Address    = $locator->get('UserAddress');
                $Profiles   = $locator->get('UserProfile');
                $PosadaSKUS = ['GP-KTS01', 'GP-PKTS02', 'GP-KTS03'];
                $CeverSKUS  = ['CEVER15', 'CEVER25', 'CEVER50'];
                $cartsTbl   = $locator->get('ShoppingCarts');
                $profile    = $Profiles->find("byUserID", ["userID" => $User['id']])->epilog('FOR UPDATE')->first();
                $address    = $Address->find("byUserID", ["userID" => $User['id']])->find("compactAddress")->disableHydration()->first();

                if (empty($address)) {
                    throw new Exception\ForbiddenException('Aún no registra una dirección de entrega. El canje no puede ser procesado. ' . $User['id']);
                }

                $items        = [];
                $price        = 0;
                $cever        = false;
                $itemsP       = [];
                $itemsC       = [];
                $ine_file     = '';
                $rfc_file     = '';
                $voucher_file = '';

                // Buscar cever SKU
                foreach ($data as $product) {
                    if (in_array($product->part_number, $CeverSKUS)) {
                        $cever = true;
                        break;
                    }
                }

                if ($cever) {
                    if (empty($this->request->getData('rfc'))) {
                        throw new Exception\ForbiddenException('Falta la imagen del RFC para realizar la redención');
                    }
                    if (empty($this->request->getData('ine'))) {
                        throw new Exception\ForbiddenException('Falta la imagen del INE para realizar la redención');
                    }
                    if (empty($this->request->getData('domicilio'))) {
                        throw new Exception\ForbiddenException('Falta la imagen del comprobante de domicilio para realizar la redención');
                    }

                    $saveDir = WWW_ROOT . 'upload' . DS . 'incentive' . DS . 'cever' . DS;

                    if (!empty($this->request->getData('rfc'))) {
                        $rfc_file = 'rfc-' . $profile['sharp'] . date('-Ymd-his') . '.png';
                        $temp = $saveDir . $rfc_file;
                        $imagenBinaria = base64_decode($this->request->getData('rfc'));
                        $bytes = file_put_contents($temp, $imagenBinaria);
                    }
                    if (!empty($this->request->getData('ine'))) {
                        $ine_file = 'ine-' . $profile['sharp'] . date('-Ymd-his') . '.png';
                        $temp = $saveDir . $ine_file;
                        $imagenBinaria = base64_decode($this->request->getData('ine'));
                        $bytes = file_put_contents($temp, $imagenBinaria);
                    }
                    if (!empty($this->request->getData('domicilio'))) {
                        $voucher_file = 'domicilio-' . $profile['sharp'] . date('-Ymd-his') . '.png';
                        $temp = $saveDir . $voucher_file;
                        $imagenBinaria = base64_decode($this->request->getData('domicilio'));
                        $bytes = file_put_contents($temp, $imagenBinaria);
                    }
                }

                foreach ($data as $product) {
                    $price += $product->points_value * $product->quantity;

                    for ($i = 0; $i < $product->quantity; $i++) {
                        $itemProduct = [
                            "price"         => $product->points_value,
                            'quantity'      => 1,
                            'product_id'    => $product->id,
                            "product_name"  => $product->name,
                            "product"       => $product
                        ];

                        $items[] = $itemProduct;

                        if (in_array($product->part_number, $PosadaSKUS)) {
                            $itemsP[] = $itemProduct;
                        }

                        if (in_array($product->part_number, $CeverSKUS)) {
                            $itemsC[] = $itemProduct;
                        }
                    }

                    $userRepository->shoppingCart()->items()->remove($shoppingCart, $product->id, false);
                }

                $available = $profile->accumulated_points - $profile->redeemed_points; #PUNTOS DISPINIBLES

                if ($price > $available) {
                    throw new Exception\ForbiddenException('No dispone de saldo suficientes para realizar la redención');
                }

                $orderRepo = $this->getRepository("Order");
                foreach ($items as $key => $val) {
                    $product                 = $val["product"];
                    $orderRepo->setProduct($product->id, 1);
                }
                //creamos la orden al ms
                $order = $orderRepo->createOrder($profile, $address);
                $orders[]                = $order;
                
                foreach ($orders as $key => $valOrder) {
                    foreach ($valOrder as $singleOrder) {
                        foreach ($items as $key => $val) {
                            $product                 = $val["product"];
                            $this->removeFromCart($singleOrder->products[0]->product_id);
                            if (in_array($product->part_number, $PosadaSKUS)) {
                                foreach ($itemsP as $ProdPoKey => $ProdPoValue) {
                                    if ($ProdPoValue['product_id'] == $product->id) {
                                        $itemsP[$ProdPoKey]['order_id'] = $singleOrder->id;
                                    }
                                }
                            }

                            if (in_array($product->part_number, $CeverSKUS)) {
                                foreach ($itemsC as $ProdCevKey => $ProdCevValue) {
                                    if ($ProdCevValue['product_id'] == $product->id) {
                                        $itemsC[$ProdCevKey]['order_id'] = $singleOrder->id;
                                    }
                                }
                            }
                        }
                    }
                }

                $profile->redeemed_points += $price;
                $Profiles->save($profile);

                if (!empty($profile->email)) {
                    $this->getMailer("PurchaseOrder")->shoppingCart($currentUser, $orders);

                    if (!empty($itemsP)) {
                        foreach ($itemsP as $productKey => $productValue) {
                            $emails = $locator->get('EmailPosadas');
                            $email  = $emails->newEntity([
                                'order_id'      => $productValue["order_id"],
                                'user_name'     => $profile->first_names . ' ' . $profile->last_names,
                                'user_email'    => $profile->email,
                                'user_phone'    => $profile->cellphone,
                                'product_name'  => $productValue["product_name"],
                                'status'        => 'PENDING',
                            ]);
                            $emails->save($email);
                        }
                    }

                    if (!empty($itemsC)) {
                        foreach ($itemsC as $productKey => $product) {
                            $path      = WWW_ROOT . 'upload' . DS . 'incentive' . DS . 'cever' . DS;
                            $documents = $this->CertificateDocuments->newEntity([
                                'order_id'          => $product["order_id"],
                                'img_rfc'           => $rfc_file,
                                'img_ine'           => $ine_file,
                                'img_proof_address' => $voucher_file,
                                'pdf_rfc'           => null,
                                'pdf_ine'           => null,
                                'pdf_proof_address' => null,
                                'image_path'        => $path
                            ]);
                            $this->CertificateDocuments->save($documents);
                        }
                    }
                }
            } catch (\Throwable $th) {
                foreach ($orders as $key => $valOrder) {
                    foreach ($valOrder as $singleOrder) {
                        $this->getRepository("Order")->cancelByID($singleOrder->id);
                    }
                }

                throw $th;
            }
        });

        $this->set([
            'success'   => true,
            'available' => $profile->accumulated_points - $profile->redeemed_points
        ]);
    }

    public function getCart()
    {
        $this->request->allowMethod('GET');

        $userRepository = $this->getRepository("User");
        $shoppingCart   = $userRepository->shoppingCart()->findOrCreate($this->Auth->user('id'));

        return $this->sendJSONResponse($userRepository->shoppingCart()->items()->getFormatted($shoppingCart));
    }

    public function addToCart()
    {
        $this->request->allowMethod('PUT');

        $requestData    = $this->request->getData();
        $userRepository = $this->getRepository("User");
        $shoppingCart   = $userRepository->shoppingCart()->findOrCreate($this->Auth->user('id'));

        return $this->sendJSONResponse(
            // Funcion para añadir producto o actualizar la cantidad de un producto.
            $userRepository->shoppingCart()->items()->add($shoppingCart, $requestData["product_id"], $requestData["quantity"])
        );
    }

    /*** Removes an item from the current user's shopping cart ***/
    public function removeFromCart($productID = 0)
    {
        // $this->request->allowMethod('DELETE');

        $userRepository = $this->getRepository("User");
        $shoppingCart   = $userRepository->shoppingCart()->findOrCreate($this->Auth->user('id'));

        $this->set(
            // Funcion para añadir producto o actualizar la cantidad de un producto.
            $userRepository->shoppingCart()->items()->remove($shoppingCart, $productID)
        );
    }

    /*** - ADAPTADO CON SAAS - Purchase Orders list ***/
    public function main()
    {
        $this->request->allowMethod('GET');

        $orders     = [];
        $saasOrders = $this->getRepository("Order")->getByBuyerReference($this->getRepository("UserProfile")->getBuyerRefByUserID($this->Auth->user('id')));

        foreach ($saasOrders as $order) {
            $row    = (object) $order;
            $isDHL  = !empty(trim($row->tracking_number)) && \preg_match('/^[0-9]+$/', trim($row->tracking_number));
            $link   = !$isDHL ? '' : 'https://www.dhl.com/mx-es/home/rastreo.html?tracking-id=' . trim($row->tracking_number);
            $status = $row->status;

            if ($status == "SENT") {
                $status = "TRANSIT";
            }

            $orders[] = [
                'id'                => $row->id,
                'total'             => intval($row->product_points_value),
                'status'            => $status,
                'tracking_number'   => empty(trim($row->tracking_number)) ? 'Pendiente' : $row->tracking_number,
                'created'           => $row->created_at,
                'dhl'               => $isDHL,
                'link'              => $link,
                'modified'          => $row->updated_at,
                'product'           => [
                    'name'          => $row->product_name,
                    'part_number'   => $row->product_part_number,
                    'category'      => $row->product_category,
                    'subcategory'   => $row->product_sub_category,
                    'vendor'        => $row->product_vendor,
                    'description'   => $row->product_description,
                    'price'         => intval($row->product_points_value),
                    'quantity'      => $row->product_quantity,
                ]
            ];
        }

        return $this->sendJSONResponse($orders);
    }
}
