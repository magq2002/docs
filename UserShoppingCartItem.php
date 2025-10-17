<?php

namespace App\Repositories\User;

use App\Repositories\UserRepository;
use App\Repositories\CatalogRepository;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\InternalErrorException;

class UserShoppingCartItem{

    use LocatorAwareTrait;

    private $model;
    private $userService;
    private $catalogService;

    public function __construct(){
        $this->model          = $this->getTableLocator()->get("CartItems");
        $this->userService    = new UserRepository();
        $this->catalogService = new CatalogRepository();
    }

    public function getByProductID($shoppingCart = null, $productID = null, $returnQuery = false){
        // Validate card and product
        $this->validateBasics($shoppingCart, $productID);

        return $this->model->find("byProductID", compact("productID"))->find("byShoppingCart", ["shoppingCartID" => $shoppingCart->id])->first();
    }

    public function getFormatted($shoppingCart, $forcePointsValue = true){
        // Si el usuario especificado no es mayor a 0 mandar error1
        if( empty($shoppingCart) ){
            throw new NotFoundException("No fue posible encontrar el carrito de compras.");
        }

        // Obtener la referencia de los productos
        $items       = [];
        $products    = [];
        $productsIDS = [];

        // Dar formato a los valores para que sea mas facil leer
        foreach( $this->model->find("byShoppingCart", ["shoppingCartID" => $shoppingCart->id])->toArray() as $item ){
            $productsIDS[]                = $item["product_id"];
            $items[ $item["product_id"] ] = $item;
        }

        // Iterar los productos para asignarles la cantidad
        foreach($this->catalogService->products()->getValidProducts($productsIDS, $forcePointsValue) as $product){
            // Verificar si el ID esta seteado y si es asi, asignar cantidad a los productos
            if( !empty($item = $items[$product["id"]]) ){
                $product["quantity"]    = $item["quantity"];
                $product["stock"]       = empty($product["stock"]) ? "0" : "{$product['stock']}";
                $product["desc"]        = $product["description"];

                $product["description"] = [
                    "large"  => $product["description"],
                    "short"  => $product["description"],
                    "medium" => $product["description"],
                ];

                $products[]          = $product;
            }
        }

        // Regresar los items con la propiedad general
        return [
            "cart"  => $products,
            'cever' => false
        ];
    }

    public function add($shoppingCart = null, $productID = null, $quantity = 1){
        // Validate card and product
        $this->validateBasics($shoppingCart, $productID);
        // Si la cantidad esta vacia o no es númerico setear 1 por defecto.
        if( empty($quantity) || !is_numeric($quantity) ){
            $quantity = 1;
        }
        // Verificar si el producto ya se encuentra en el carrito
        else if( empty($item = $this->getByProductID($shoppingCart, $productID)) ){
            $item = $this->model->newEntity();

            $item->quantity         = $quantity;
            $item->product_id       = $productID;
            $item->shopping_cart_id = $shoppingCart->id;
        }
        // Si el item existe solo sumar la cantidad
        $item->quantity = $quantity;

        if( !$this->model->save($item) ){
            throw new InternalErrorException("No se pudo agregar el producto al carrito de compras.");
        }

        return $this->getFormatted($shoppingCart);
    }

    public function remove($shoppingCart, $productID, $showCartItems = true)
    {
        $this->validateBasics($shoppingCart, $productID);
    
        $item = $this->model
            ->find("byProductID", compact("productID"))
            ->find("byShoppingCart", ["shoppingCartID" => $shoppingCart->id])
            ->first();
    
            if ($item) {
                $this->model->deleteAll(['id' => $item->id]);
            }
    }

    private function validateBasics($shoppingCart, $productID){
        // Si el usuario especificado no es mayor a 0 mandar error1
        if( empty($shoppingCart) ){
            throw new NotFoundException("No fue posible encontrar el carrito de compras.");
        }
        // Si el producto especificado no es mayor a 0 mandar error
        else if( empty($productID) ){
            throw new NotFoundException("El producto no se encuentra disponible por el momento.");
        }
    }

}
