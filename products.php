<?php
require 'vendor/autoload.php';

define('IMAGES_DIR', $_SERVER['DOCUMENT_ROOT'] . '/catalog');

set_time_limit(0);
$products = new Products();
$products->fixImages();

$productId = [];
$categories = $products->getNoRootCategories();

if (!is_dir(IMAGES_DIR)) {
    if (!mkdir(IMAGES_DIR)) {
        die('Не удается создать директорию для сохранения изображений. Проверьте права проекта.');
    }
}

foreach ($categories as $category) {
    echo "Взяли категорию ".$category['category_id']."<br>";
    for ($page = 0; $page < 6; $page++) {
        $productOnPage = $products->getPage($category['donor_link'], $page);
        if (count($productOnPage) == 0) {
            echo "По адресу ".$category['donor_link']." и странице ". $page ." нет ни одного продукта<br>";
            break;
        }
        echo "По адресу ".$category['donor_link']." и странице ". $page ." нашли ". count($productOnPage) ." продуктов<br>";
        echo '<pre>';
        foreach ($productOnPage as $product) {
            $productData = $products->getProduct($product['url']);
            $productData['category_id'] = $category['category_id'];
            $productData['donor_link'] = $product['url'];
            $isProductExist = $products->isProductExist($product);
            if ($isProductExist) {
                echo "Такой продукт есть<br>";
                $products->updateProduct($isProductExist, $productData);
                $productId[] = $productData;
                echo "Смотрим инфу по  " . $product['url'] . " нашли " . count($productData) . " данных<br>";
            } else {
                echo "Такого продукта еще нет<br>";
                $productId[] = $products->storeProduct($productData);
                $productId[] = $productData;
            }
        }
    }
}

use GuzzleHttp\Client;

class Products
{
    private $client;
    private $options;
    private $mysql;

    function __construct()
    {
        $this->mysql = new mysqli('localhost', 'root', 'Devex123!', 'petrovich') or die(fakapsbazoy);
        $this->mysql->query("SET NAMES utf8");

        $this->options = ['base_uri' => 'https://petrovich.ru/'];
        $this->client = new Client($this->options);
    }

    /*
     * @return $result array
     */
    public function getPage($catUrl, $page = 0): array
    {
        //Список всех главных категорий
        $rootCategories = [];
        $pattern = '/<a\s[^>]*href=\"([^\"]*)\" class="listing__product-title([^\"]*)"([^\>]*)>(.*)<\/a>/siU';
        $allow_redirects = true;
        if($page > 0) {
            $allow_redirects = false;
        }
        $response = $this->client->request('GET', $catUrl.'?p='.$page, ['allow_redirects' => $allow_redirects]);
        if ($response->getStatusCode() == 200) {
            $feed = $response->getBody();
            preg_match_all($pattern, $feed, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $category = [
                    'url' => $match[1],
                    'name' => $match[4],
                ];
                array_push($rootCategories, $category);
            }
        }
        return $rootCategories;
    }

    public function getProduct(string $productUrl): array
    {
        $productData = [];
        $patternName = '|<h1 class="product--title"([^>]*)>(.*)</h1>|siU';
        $patternDescription = '|<div class="text--common"([^>]*)>(.*)</div>|siU';
        $patternDescriptionFormatted = '|<div class="text--formated"([^>]*)>(.*)</div>|siU';
        $patternTechInfo = '|<div class="product--specification-info"([^>]*)>(.*)</div>([^<]*)</div>([^<]*)<div id="react-app" class="react-app">|siU';
        $patternDetail = '|<div class="tabs__product-details([^"]*)" >(.*)</div>|isU';
        $patternPrice = '|<span class="retailPrice"([^>]*)>(.*)</span>|isU';
        $patternPriceUnit = '|<div class="price--unit"([^>]*)>(.*)</div>|isU';
        $patternImg = '|<div class="image-gallery-image">([^<]*)<img src="(.*)"([^s]*)|isU';
        $patternImgs = '|<div class="image-gallery-image right">([^<]*)<img src="(.*)"([^s]*)|isU';
        $patternSize = '/([0-9]+х[0-9]+х[0-9]+)/';
        $patternWeight = '/Вес брутто:.+([0-9]+\.[0-9]+) кг/';

        $response = $this->client->request('GET', $productUrl);
        if ($response->getStatusCode() == 200) {
            $feed = $response->getBody();
            preg_match_all($patternName, $feed, $matchesName, PREG_SET_ORDER);
            $productData['name'] = $matchesName[0][2];

            preg_match_all($patternDescription, $feed, $matchesDescription, PREG_SET_ORDER);
            $productData['description'] = trim($matchesDescription[0][2]);
            
            preg_match_all($patternDescriptionFormatted, $feed, $matchesDescriptionFormatted, PREG_SET_ORDER);
            $productData['formatted'] = trim($matchesDescriptionFormatted[0][2]);

            preg_match_all($patternTechInfo, $feed, $matchesInfo, PREG_SET_ORDER);
            $productData['tech_info'] = trim($matchesInfo[0][2]);

            preg_match_all($patternDetail, $feed, $matchesDetail, PREG_SET_ORDER);
            $productData['details'] = $matchesDetail[0][2];

            preg_match_all($patternPrice, $feed, $matchesPrice, PREG_SET_ORDER);
            preg_match('/data-default-price="([^"]+)"/', $matchesPrice[0][1], $resultPrice);
            $productData['price'] = (float) str_replace(' ', '', str_replace(',', '.', trim($resultPrice[1])));

            preg_match_all($patternPriceUnit, $feed, $matchesPriceUnit, PREG_SET_ORDER);

            if (preg_match('/<div\D+data-unit-type="default"\D+>/', $feed, $productPriceUnit)) {
                $productData['price_unit'] = 'Цена за ' . strip_tags(trim($productPriceUnit[0]));
            } else {
                $productData['price_unit'] = strip_tags(trim($matchesPriceUnit[0][2]));
            }

            preg_match_all($patternImgs, $feed, $matchesImgs, PREG_SET_ORDER);
            if (count($matchesImgs) > 1) {
                foreach($matchesImgs as $image) {
                    $productData['images'][] = $image[2];
                }
            }
            preg_match_all($patternImg, $feed, $matchesImg, PREG_SET_ORDER);
            $productData['image'] = 'https:' . strip_tags(trim($matchesImg[0][2]));

            if (!preg_match($patternSize, $productData['name'], $productSize)) {
                preg_match($patternSize, $productData['description'], $productSize);
            }

            if (!empty($productSize)) {
                $productSize = explode('х', $productSize[1]);
                $productData['size']['length'] = $productSize[0];
                $productData['size']['width'] = $productSize[1];
                $productData['size']['height'] = $productSize[2];
            }

            preg_match($patternWeight, $productData['formatted'], $productWeight);

            if (!empty($productWeight)) {
                $productData['weight'] = $productWeight[1];
            }
        }

        return $productData;
    }

    public function fixImages()
    {
        $itemArray = [];
        $sql = "SELECT product_id, image FROM oc_product
                WHERE image is NOT NULL";
        $result = $this->mysql->query($sql);

        while ($row = $result->fetch_assoc()) {
            $itemArray[] = $row;
        }

        foreach ($itemArray as $product) {
            $dProduct = explode('?', $product['image']);
            $productImage = $dProduct[0];
            $productId = $product['product_id'];
            $sql = "UPDATE oc_product set `image` = '$productImage' WHERE product_id = '$productId' ";
            $this->mysql->query($sql);
        }

        $itemArray2 = [];
        $sql2 = "SELECT product_image_id, product_id, image FROM oc_product_image
                WHERE image is NOT NULL";
        $result2 = $this->mysql->query($sql2);

        while ($row = $result2->fetch_assoc()) {
            $itemArray2[] = $row;
        }

        foreach ($itemArray2 as $product) {
            $dProduct = explode('?', $product['image']);
            $productImage = $dProduct[0];
            $productImgId = $product['product_image_id'];
            $sql = "UPDATE oc_product_image set `image` = '$productImage' WHERE product_image_id = '$productImgId' ";
            $this->mysql->query($sql);
        }

        $itemArray3 = [];
        $sql3 = "SELECT product_id, image FROM oc_product
                WHERE image is NULL OR image = '' ";
        $result3 = $this->mysql->query($sql3);

        while ($row = $result3->fetch_assoc()) {
            $itemArray3[] = $row;
        }

        foreach ($itemArray3 as $product) {
            $itemArray4 = [];

            $productId = $product['product_id'];
            $sql4 = "SELECT product_id, image FROM oc_product_image
                WHERE product_id = '$productId' ";
            $result4 = $this->mysql->query($sql4);

            while ($row = $result4->fetch_assoc()) {
                $itemArray4[] = $row;
            }

            $productImage = $itemArray4[0]['image'];
            $sql = "UPDATE oc_product set `image` = '$productImage' WHERE product_id = '$productId' ";
            $this->mysql->query($sql);
        }

    }

    public function getNoRootCategories()
    {
        $categories = [];
        $sql = "SELECT category_id, donor_link FROM oc_category LEFT JOIN oc_category_description USING(category_id)
                WHERE oc_category.parent_id > 0";
        $result = $this->mysql->query($sql);
        if (!$result) {
            $message  = 'Неверный запрос: ' . $this->mysql->error . "\n";
            $message .= 'Запрос целиком: ' . $sql;
            die($message);
        }
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        return $categories;
    }

    public function isProductExist(array $productInfo)
    {
        $res = false;
        $products = [];
        $productModel = $productInfo['url'];
        $productName = $productInfo['name'];
        $sql = "SELECT * FROM oc_product LEFT JOIN oc_product_description USING(product_id) 
                WHERE oc_product.model = '$productModel' AND oc_product_description.name = '$productName'";
        $result = $this->mysql->query($sql);
        if (!$result) {
            $message  = 'Неверный запрос: ' . $this->mysql->error . "\n";
            $message .= 'Запрос целиком: ' . $sql;
            die($message);
        }
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        if (count($products) > 0) {
            $res = $products[0]['product_id'];
        }
        return $res;
    }

    public function storeProduct(array $productData)
    {
        $image = null;
        if (isset($productData['image'])) {
            preg_match('/\/([0-9]+)\//', $productData['image'], $catalogId);
            if (!empty($catalogId)) {
                $imageName = 'product-' . $catalogId[1] . '.jpg';
                $image = "catalog/$imageName";
                file_put_contents(IMAGES_DIR . '/' . $imageName, file_get_contents($productData['image']));
            }
        }

        $model = $productData['donor_link'];
        $price = $productData['price'];
        $date_available = date('Y-m-d');
        $date_added = date('Y-m-d H:i:s');
        $date_modified = date('Y-m-d H:i:s');
        $weight = !empty($productData['weight']) ? str_replace(',', '.', trim($productData['weight'])) : 0;
        $length = !empty($productData['size']['length']) ? $productData['size']['length'] : 0;
        $width = !empty($productData['size']['width']) ? $productData['size']['width'] : 0;
        $height = !empty($productData['size']['height']) ? $productData['size']['height'] : 0;

        $sql = "INSERT INTO oc_product (`model`, `image`, `price`, `weight`, `length`, `width`, `height`, `date_available`, `date_added`, `date_modified`) 
                VALUES ('$model', '$image', '$price', '$weight', '$length', '$width', '$height', '$date_available', '$date_added', '$date_modified')";
        $this->mysql->query($sql);
        echo $this->mysql->error;

        $product_id =  $this->mysql->insert_id;
        echo "<br>";
        echo $this->mysql->error;
        $name = $productData['name'];
        $meta_title = $productData['name'];
        $donor_link = $productData['donor_link'];
        $price_unit = $productData['price_unit'];
        $description = $productData['description'].$productData['tech_info'].$productData['details'];

        $sql2 = "INSERT INTO oc_product_description (`product_id`, `name`, `meta_title`, `donor_link`, `price_unit`, `description`) 
                 VALUES ('$product_id', '$name', '$meta_title', '$donor_link', '$price_unit', '$description')";
        $this->mysql->query($sql2);
        echo $this->mysql->error;

        if (isset($productData['images']) && count($productData['images']) > 0) {
            foreach ($productData['images'] as $key => $image) {
                $i = !empty($image) ? 'catalog/' . basename($image) : null;
                if (!empty($image)) {
                    preg_match('/\/([0-9]+)\//', $image, $catalogId);
                    if (!empty($catalogId)) {
                        $imageName = 'product-' . $catalogId[1] . "-$key.jpg";
                        $image = "catalog/$imageName";
                        file_put_contents(IMAGES_DIR . '/' . $imageName, file_get_contents($image));
                    }
                }
                $sql3 = "INSERT INTO oc_product_image (`product_id`, `image`) 
                    VALUES ('$product_id', '$i')";
                $this->mysql->query($sql3);
                echo $this->mysql->error;
            }
        }

        $category_id = $productData['category_id'];

        $sql4 = "INSERT INTO oc_product_to_category (`category_id`, `product_id`) 
                    VALUES ('$category_id', '$product_id')";
        $this->mysql->query($sql4);
        echo $this->mysql->error;

        $sql5 = "INSERT INTO oc_product_to_store (`product_id`, `store_id`) 
                    VALUES ('$product_id', '0')";
        $this->mysql->query($sql5);
        echo $this->mysql->error;

        $sql6 = "INSERT INTO oc_product_to_layout (`product_id`, `layout_id`, `store_id`) 
                    VALUES ('$product_id', '0', '0')";
        $this->mysql->query($sql6);
        echo $this->mysql->error;

        return $product_id;
    }

    /**
     * @param int $product_id Editing product id
     * @param array $productData Information about product
     */
    public function updateProduct(int $product_id, array $productData) {
        $category_id = $productData['category_id'];

        $price = $productData['price'];
        $date_modified = date('Y-m-d H:i:s');

        $this->mysql->query("UPDATE oc_product
                             SET `price` = '$price',
                                 `date_modified` = '$date_modified'
                             WHERE `product_id` = '$product_id'");
        echo $this->mysql->error;

        $this->mysql->query("UPDATE oc_product_to_category
                             SET `category_id` = '$category_id'
                             WHERE `product_id` = '$product_id'");
        echo $this->mysql->error;
    }
}
