<?php

namespace Showcases\Service\ProductsCardsSearchService;

use Application\Exceptions\NoProductSearchResult;
use Application\Service\AbstractService;
use Application\Service\SolrService;
use Doctrine\ORM\EntityManager;
use Interop\Container\ContainerInterface;
use Interfaces\Showcases\Services\HierarchyCollectionServiceInterface as CollectionInterface;
use Interfaces\Showcases\Services\ProductSearchServiceInterface;
use Showcases\Entity\CategoryProperty;
use Showcases\Repository\CategoryPropertyRepository;
use Showcases\Repository\ProductCardRepository;
use Showcases\Repository\ProductReviewRepository;
use Showcases\Repository\ProductsRepository;
use Showcases\Service\CategoryService;
use Showcases\Service\ProductsService;

/**
 * Class ProductsSearchService
 * @package Showcases\Service
 */
class ProductsCardsSearchService extends AbstractService implements ProductSearchServiceInterface
{

    protected $container;
    protected $em;
    protected $repository;
    protected $solrService;
    protected $productCardRepository;
    protected $productReviewRepository;
    protected $productService;
    protected $categoryService;
    protected $categoryPropertyRepository;
    protected $collectionService;
    private $categoryFound = false;

    public function __construct(
        ContainerInterface $container,
        EntityManager $em,
        ProductsRepository $repository,
        SolrService $solrService,
        ProductCardRepository $productCardRepository,
        ProductReviewRepository $productReviewRepository,
        ProductsService $productService,
        CategoryService $categoryService,
        CategoryPropertyRepository $categoryPropertyRepository,
        CollectionInterface $hierarchyCollectionService
    ) {
        $this->container = $container;
        $this->em = $em;
        $this->repository = $repository;
        $this->solrService = $solrService;
        $this->productCardRepository = $productCardRepository;
        $this->productReviewRepository = $productReviewRepository;
        $this->productService = $productService;
        $this->categoryService = $categoryService;
        $this->categoryPropertyRepository = $categoryPropertyRepository;
        $this->collectionService = $hierarchyCollectionService;
    }

    /**
     * Поиск карточек товаров со всеми зависимостями
     * @param array $params расширенный фильтр
     * @return array
     *
     * @throws NoProductSearchResult
     */
    public function get(array $params): array
    {
        $isAutocomplete = $params['isAutocomplete'];

        if (! in_array(strtolower($params['lang']), ['ru', 'en', 'cn'])) {
            $params['lang'] = 'ru';
        }
        $params['lang'] = ucfirst(strtolower($params['lang']));

        [$productCategoriesIDs, $categoriesArray] = $this->getProductsCategories($params);

        [$productCards, $totalCount, $transliterate] = $this->getProductsCards($params, $productCategoriesIDs);

        if($transliterate) $params['name'] = $this->switcherRu($params['name']);

        if ($productCards) {
            // TODO: рейтинги надо переносить в солр
            $productCardsIds = array_map(function ($productCard) {
                return $productCard['productCardId'];
            }, $productCards);

            $reviews = $this->productReviewRepository->getAverageAndCountReviews($productCardsIds);

            foreach ($productCards as $i => $productCard) {
                foreach ($reviews as $review) {
                    if ($review['productCardId'] == $productCard['productCardId']) {
                        $productCards[$i]['rating'] = round($review['rating'], 1);
                        $productCards[$i]['countReviews'] = $review['countReviews'];
                    }
                }
            }
        }

        $dataArray = [
            '_name'                 => $params['name'],
            'products_cards'        => $productCards,
            'products_categories'   => ($isAutocomplete) ? null : $categoriesArray,
            'products_properties'   => ($isAutocomplete) ? null : $this->getProductsProperties($productCategoriesIDs, $params),
            'categories_properties' => ($isAutocomplete) ? null : $this->getCategoriesProperties($productCategoriesIDs)
        ];

        return [$dataArray, $totalCount];
    }

    /**
     * Поиск товаров в solr.
     *
     * @param array $params
     * @param array $productCategoriesIDs
     *
     * @return array
     *
     * @throws NoProductSearchResult
     */
    protected function getProductsCards(array $params, $productCategoriesIDs = []): array
    {
        if (!empty($params['priceFrom']) || !empty($params['priceTo'])) {
            $params['price'] = [
                'from' => $params['priceFrom'],
                'to'   => $params['priceTo'],
            ];
        }
        $queryString = $params['name'];
        if (empty($params['name'])) {
            $params['name'] = ['*'];
        } else {
            $params['name'] = (!$this->categoryFound) ? explode(' ', $params['name']) : ['*'];
        }

        $params['feedIds'] = (!isset($params['feedId'])) ? null : [$params['feedId']];
        $params['moderateStatuses'] = (!isset($params['status'])) ? null : [$params['status']];
        $params['categoryIds'] = (!$this->categoryFound) ? $productCategoriesIDs : [$this->categoryFound];
	    $params['facet.prefix'] = mb_strtolower(mb_substr($queryString, 0, -2));

        $result = $this->solrService->searchProductCards($params);

        if (!isset($result['productCards']) || count($result['productCards']) < 1) {
            throw new NoProductSearchResult('No search products result', 404);
        }

        $productsCards = $this->setProductsLinks($result['productCards']);

        return [$productsCards, $result['totalCount'], $result['transliterate']];
    }

    /**
     * Генерирует массив с массивами Х_х
     * 1. массив из 25 значений, отсортированный по количетву товара в каждой категории.
     *    [ 0 => id ]
     * 2. тот же массив, но c данными о категории из репозитория
     *    [ 0 => [id, name, ...]
     *
     * @param array $params
     * @return array
     */
    protected function getProductsCategories(array $params): array
    {
        // Если при запросе указан ИД категории, другие категории не показываем
        if (isset($params['category']) && $params['category'] > 0) {
            return [[],[]]; // Робот [.]_[.] //или сиськи
        }

        $categoryRepository = $this->categoryService->getRepository();

        // Массив ИД категорий, отсортированный по количетву товара в каждой категории
        $sortedSolrCatIds = $this->solrService->getGroupedCategories($params);

        // Массив категорий не отсортированный
        // TODO: можно перенести в солр для небольшого ускорения
        $pgCategoriesData = $categoryRepository->getCategoriesInIds($sortedSolrCatIds, 25);

        // Сортируем полученные из постгры данные в соответствие с ИД, полученными от солр
        $_tmp_categories = [];
        foreach($pgCategoriesData as $category) {
            $_tmp_categories[$category['id']] = $category;
        }
        $pgCategoriesData = [];
        foreach($sortedSolrCatIds as $key => $id) {
            $pgCategoriesData[$key] = $_tmp_categories[$id];
        };
        unset($_tmp_categories);

        return [
            $sortedSolrCatIds,
            $pgCategoriesData,
        ];
    }

    protected function getProductsProperties(array $categoryIDs, array $params): array
    {
        $params['objectResponse'] = 1;
        $categoryId = end( $categoryIDs);
        return $this->productService->getProductPropertyValues($categoryId,0, $params);
    }

    protected function getCategoriesProperties($categoriesIDs): ?array
    {
        if (empty($productCategoriesIDs)) return null;
        return $this->categoryPropertyRepository->getCategoryPropertiesList(0, $categoriesIDs);
//        return $this->categoryPropertyService->getCategoryPropertiesList(0, $categoriesIDs, 0, 0);
    }

    private function productsCategoriesFilterByHierarchy(array $products, int $exactlyCategory): array
    {
        $result = [];
        $notcomplete = [];
        $node = $this->collectionService
                     ->getNodeById($exactlyCategory);
        $neighboringCategoriesIds = $this->collectionService
                                         ->getNextBranchNodes($node)
                                         ->onlyIds();
        foreach ($products as $product)
        {
            if ($product['categoryId'] === $exactlyCategory)
            {
                array_unshift($result, $product);
                continue;
            }
            if (in_array($product['categoryId'], $neighboringCategoriesIds))
            {
                array_push($result, $product);
                continue;
            }

            array_push($notcomplete, $product);
        }

        return array_merge($result, $notcomplete);
    }

    /**
     * @param array $params
     * @return array
     */
    private function getProductsCategoriesIDs(array $params): array
    {
        $searchQuery = $params['name'];
        $lang = $params['lang'];
        $marketplace = $params['marketplace'];
        $marketTypeIndividual = $params['marketTypeIndividual'];

        /** TODO ALERT: CategoryService::userSearchCategory() may return ApiProblemResponse */
        [$productCategoriesIDs, $categoryFound] = $this->categoryService->userSearchCategory($searchQuery, $lang, $marketplace, $marketTypeIndividual);
        $this->categoryFound = $categoryFound;

        return $productCategoriesIDs;
    }

    private function setProductsLinks(array $products): array
    {
        $productsLink = $this->repository->getProductsLinks($products);
        foreach ($products as &$item)
            $item['link'] = ($productsLink[$item['productId']]) ?? '';

        return $products;
    }   

    private function convertEntitiesToArray(array &$categories)
    {
        foreach ($categories as $key => $category)
            $categories = $this->replaceArrayKey($categories, $key, (int)$category['id']);
    }

	private function getPartialsResults($product, string $searchString, string $string, array $facets): ?array
	{
		$searchString = explode(' ', $searchString);
		$matchStringLen = mb_strlen(mb_substr($searchString[0], 0, -1));
		foreach ($facets as $facet => $count)
		{
			if (mb_strlen($facet) > $matchStringLen)
				continue;

			if (preg_match("/^$facet(.*)\s/", $string))
				return $product;
		}

		return null;
    }

    /**
     * Replaces an array key and preserves the original
     * order.
     *
     * @param $array array
     * @param $oldKey
     * @param $newKey
     *
     * @return array
     */
    private function replaceArrayKey($array, $oldKey, $newKey){

        if(!isset($array[$oldKey]))
            return $array;

        $arrayKeys = array_keys($array);
        $oldKeyIndex = array_search($oldKey, $arrayKeys);
        $arrayKeys[$oldKeyIndex] = $newKey;
        $newArray =  array_combine($arrayKeys, $array);
        return $newArray;
    }

    private function switcherRu($value)
    {
        $converter = array(
            'f' => 'а',	',' => 'б',	'd' => 'в',	'u' => 'г',	'l' => 'д',	't' => 'е',	'`' => 'ё',
            ';' => 'ж',	'p' => 'з',	'b' => 'и',	'q' => 'й',	'r' => 'к',	'k' => 'л',	'v' => 'м',
            'y' => 'н',	'j' => 'о',	'g' => 'п',	'h' => 'р',	'c' => 'с',	'n' => 'т',	'e' => 'у',
            'a' => 'ф',	'[' => 'х',	'w' => 'ц',	'x' => 'ч',	'i' => 'ш',	'o' => 'щ',	'm' => 'ь',
            's' => 'ы',	']' => 'ъ',	"'" => "э",	'.' => 'ю',	'z' => 'я',

            'F' => 'А',	'<' => 'Б',	'D' => 'В',	'U' => 'Г',	'L' => 'Д',	'T' => 'Е',	'~' => 'Ё',
            ':' => 'Ж',	'P' => 'З',	'B' => 'И',	'Q' => 'Й',	'R' => 'К',	'K' => 'Л',	'V' => 'М',
            'Y' => 'Н',	'J' => 'О',	'G' => 'П',	'H' => 'Р',	'C' => 'С',	'N' => 'Т',	'E' => 'У',
            'A' => 'Ф',	'{' => 'Х',	'W' => 'Ц',	'X' => 'Ч',	'I' => 'Ш',	'O' => 'Щ',	'M' => 'Ь',
            'S' => 'Ы',	'}' => 'Ъ',	'"' => 'Э',	'>' => 'Ю',	'Z' => 'Я',

            '@' => '"',	'#' => '№',	'$' => ';',	'^' => ':',	'&' => '?',	'/' => '.',	'?' => ',',
        );

        $value = strtr($value, $converter);
        return $value;
    }
}
