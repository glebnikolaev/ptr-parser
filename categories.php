<?php
require 'vendor/autoload.php';

define('IMAGES_DIR', $_SERVER['DOCUMENT_ROOT'] . '/catalog');

$categories = new Categories();
$tree = $categories->getCategoryTree();

if (!is_dir(IMAGES_DIR)) {
    if (!mkdir(IMAGES_DIR)) {
        die('Не удается создать директорию для сохранения изображений. Проверьте права проекта.');
    }
}

foreach($tree as $category) {
    if (!empty($category_id = $categories->getExistedCategoryId($category))) {
        $categories->updateCategory($category_id, $category);
    } else {
        $category_id = $categories->storeCategory($category, 0);
    }

    if (isset($category['subcategories']) && count($category['subcategories']) > 0) {
        foreach ($category['subcategories'] as $subcategory) {
            if (!empty($subCategory_id = $categories->getExistedCategoryId($subcategory))) {
                $categories->updateCategory($subCategory_id, $subcategory, $category_id);
            } else {
                $subCategory_id = $categories->storeCategory($subcategory, $category_id);
            }

            if (isset($subcategory['subcategories']) && count($subcategory['subcategories']) > 0) {
                foreach ($subcategory['subcategories'] as $subSubcategory) {
                    if (!empty($subSubCategory_id = $categories->getExistedCategoryId($subSubcategory))) {
                        $categories->updateCategory($subSubCategory_id, $subSubcategory, $subCategory_id);
                    } else {
                        $subSubCategory_id = $categories->storeCategory($subSubcategory, $subCategory_id);
                    }
                }
            }
        }
    }
}

use GuzzleHttp\Client;

class Categories
{
    private $client;
    private $options;
    private $mysql;

    function __construct()
    {
        $this->mysql = new mysqli('localhost', 'root', 'Devex123!', 'petrovich') or die(fakapsbazoy);
        $this->mysql->query("SET NAMES utf8");

        $this->prepare();

        $this->options = ['base_uri' => 'https://petrovich.ru/'];
        $this->client = new Client($this->options);
    }

    /*
     * Получаем список главных категорий
     * @return $result array
     */
    private function getRootCategories(): array
    {
        //Список всех главных категорий
        $rootCategories = [];
        $pattern = '/<a\s[^>]*href=\"([^\"]*)\" class="column_left_catalog_item([^\"]*)">(.*)<\/a>/siU';
        $response = $this->client->request('GET', '/');
        if ($response->getStatusCode() == 200) {
            $feed = $response->getBody();
            preg_match_all($pattern, $feed, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $category = [
                    'url' => $match[1],
                    'name' => $match[3],
                ];
                array_push($rootCategories, $category);
            }
        }
        return $rootCategories;
    }

    /*
     * Получаем список дочерних категорий
     * @param $rootCategory string
     * @return $result array
     */
    private function getChildCategories(string $rootCategory): array
    {
        $childCategories = [];
        $pattern = '/<li\s[^>]*class="categories_list_item small_category([^\"]*)">(.*)<\/li>/siU';

        $response = $this->client->request('GET', $rootCategory);
        if ($response->getStatusCode() == 200) {
            $feed = $response->getBody();
            preg_match_all($pattern, $feed, $matches, PREG_SET_ORDER);

            foreach ($matches as $dirtyItem) {
                array_push($childCategories, $this->urlCleaner($dirtyItem[0]));
            }
        }
        return $childCategories;
    }

    /*
     * Получаем атрибуты дочерней категории и собираем в массив
     * @param $dirtyItem string
     * @return $subCategory array
     */
    private function urlCleaner(string $dirtyItem): array
    {
        $imgPattern = '/<img src=\"([^\"]*)\"/si';
        $linkPattern = '/<a\s[^>]*href=\"([^\"]*)\" [^>]*">(.*)<\/a>/si';

        preg_match_all($imgPattern, $dirtyItem, $imgMatches, PREG_SET_ORDER);
        preg_match_all($linkPattern, $dirtyItem, $linkMatches, PREG_SET_ORDER);
        $subCategory = [
            'img' => 'https:' . $imgMatches[0][1],
            'url' => $linkMatches[0][1],
            'name' => trim(strip_tags($linkMatches[0][2])),
        ];
        return $subCategory;
    }

    /*
     * Получаем дерево категорий
     * @return $tree array
     */
    public function getCategoryTree(): array
    {
        $rootCategories = $this->getRootCategories();
        $tree = [];
        foreach ($rootCategories as $rootCategory) {
            $subCategories = $this->getChildCategories($rootCategory['url']);
            if (count($subCategories) > 0) {
                $subCategoriesTree = [];
                foreach ($subCategories as $subCategory) {
                    $subSubCategories = $this->getChildCategories($subCategory['url']);
                    if (count($subSubCategories) > 0) {
                        $subCategory['subcategories'] = $subSubCategories;
                    }
                    array_push($subCategoriesTree, $subCategory);
                }
                $rootCategory['subcategories'] = $subCategoriesTree;
            }
            array_push($tree, $rootCategory);
        }
        return $tree;
    }

    public function storeCategory(array $category, int $parent_id = 0)
    {      
        $image = null;
        if (isset($category['img'])) {
            preg_match('/\/([0-9]+)\//', $category['img'], $catalogId);
            if (!empty($catalogId)) {
                $imageName = 'category-' . $catalogId[1] . '.jpg';
                $image = "catalog/$imageName";
                file_put_contents(IMAGES_DIR . '/' . $imageName, file_get_contents($category['img']));
            }
        }

        $top = 0;
        $column = 1;
        $sort_order = 0;
        $status = 1;
        $date_added = date('Y-m-d H:i:s');
        $date_modified = date('Y-m-d H:i:s');
        $sql = "INSERT INTO oc_category (`image`, `parent_id`, `top`, `column`, `sort_order`, `status`, `date_added`, `date_modified`) 
                  VALUES ('$image','$parent_id','$top','$column','$sort_order','$status','$date_added','$date_modified')";
        $this->mysql->query($sql);

        $category_id = $this->mysql->insert_id;
        $language_id = '1';
        $name = trim($category['name']);
        $meta_title = $category['name'];
        $donor_link = $category['url'];
        $sql2 = "INSERT INTO oc_category_description (`category_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description`, `meta_keyword`, `donor_link`) 
                    VALUES ('$category_id', '$language_id', '$name', '', '$meta_title', '', '', '$donor_link')";
        $result = $this->mysql->query($sql2);

        $sql3 = "INSERT INTO oc_category_to_layout (`category_id`, `store_id`, `layout_id`) 
                    VALUES ('$category_id', '0', '0')";
        $this->mysql->query($sql3);

        $sql4 = "INSERT INTO oc_category_to_store (`category_id`, `store_id`) 
                    VALUES ('$category_id', '0')";
        $this->mysql->query($sql4);

        return $category_id;
    }

    private function prepare() {
        $this->mysql->query('UPDATE oc_category SET `status` = 0');
    }

    /**
     * @param array $category Information about category
     * @return int|null Existed category id
     */
    public function getExistedCategoryId(array $category) {
        $catName = trim($category['name']);
        $result = $this->mysql->query("SELECT category_id 
                                       FROM oc_category_description
                                       WHERE `name` LIKE '$catName'");
        $resultAssoc = $result->fetch_assoc();
        return !empty($resultAssoc) ? (int) $resultAssoc['category_id'] : null;
    }

    /**
     * @param int $category_id Editing category id
     * @param array $category_info Information about category
     * @param int $parent_id Parent category id
     */
    public function updateCategory(int $category_id, array $category_info, int $parent_id = 0) {
        $date_modified = date('Y-m-d H:i:s');

        $this->mysql->query("UPDATE oc_category 
                             SET `date_modified` = '$date_modified', 
                                 `status` = 1
                             WHERE `category_id` = '$category_id'");
        echo $this->mysql->error . '<br>';
    }
}
