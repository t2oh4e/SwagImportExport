<?php

namespace Shopware\Components\SwagImportExport\DbAdapters;

use Doctrine\Common\Collections\ArrayCollection;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\ArticleWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\CategoryWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\ConfiguratorWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\PropertyWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\TranslationWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\PriceWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\RelationWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\ImageWriter;
use Shopware\Components\SwagImportExport\DbAdapters\Articles\Write;
use Shopware\Models\Article\Article as ArticleModel;
use Shopware\Models\Article\Detail as DetailModel;
use Shopware\Models\Article\Price as Price;
use Shopware\Models\Article\Image as Image;
use Shopware\Models\Customer\Group as CustomerGroup;
use Shopware\Models\Article\Configurator;
use Shopware\Models\Media\Media as MediaModel;
use Shopware\Components\SwagImportExport\Exception\AdapterException;
use Shopware\Components\SwagImportExport\Utils\DataHelper as DataHelper;
use Shopware\Components\SwagImportExport\Utils\DbAdapterHelper;
use \Shopware\Components\SwagImportExport\Utils\SnippetsHelper as SnippetsHelper;
use Shopware\Models\Property;

class ArticlesDbAdapter implements DataDbAdapter
{

    /**
     * Shopware\Components\Model\ModelManager
     */
    protected $manager;

    protected $db;

    /**
     * Shopware\Models\Article\Article
     */
    protected $repository;
    protected $variantRepository;

    //mappers
    protected $articleVariantMap;
    protected $variantMap;

    /**
     * @var array
     */
    protected $categoryIdCollection;

    /**
     * @var array
     */
    protected $unprocessedData;

    /**
     * @var array
     */
    protected $logMessages;

    /**
     * @var array
     */
    protected $tempData;

    /**
     * @var array
     */
    protected $defaultValues = array();

    public function readRecordIds($start, $limit, $filter)
    {
        $manager = $this->getManager();

        $builder = $manager->createQueryBuilder();

        $builder->select('detail.id');

        $builder->from('Shopware\Models\Article\Detail', 'detail')
            ->orderBy('detail.articleId', 'ASC')
            ->orderBy('detail.kind', 'ASC');

        if ($filter['variants']) {
            $builder->andWhere('detail.kind <> 3');
        } else {
            $builder->andWhere('detail.kind = 1');
        }

        if ($filter['categories']) {
            $category = $this->getManager()->find('Shopware\Models\Category\Category', $filter['categories'][0]);

            $this->collectCategoryIds($category);
            $categories = $this->getCategoryIdCollection();

            $categoriesBuilder = $manager->createQueryBuilder();
            $categoriesBuilder->select('article.id')
                ->from('Shopware\Models\Article\Article', 'article')
                ->leftjoin('article.categories', 'categories')
                ->where('categories.id IN (:cids)')
                ->setParameter('cids', $categories)
                ->groupBy('article.id');

            $articleIds = array_map(function($item) {
                return $item['id'];
            }, $categoriesBuilder->getQuery()->getResult());

            $builder->join('detail.article', 'article')
                ->andWhere('article.id IN (:ids)')
                ->setParameter('ids', $articleIds);
        }

        $builder->setFirstResult($start)
            ->setMaxResults($limit);

        $records = $builder->getQuery()->getResult();

        $result = array();
        if ($records) {
            foreach ($records as $value) {
                $result[] = $value['id'];
            }
        }

        return $result;
    }

    public function read($ids, $columns)
    {
        if (!$ids && empty($ids)) {
            $message = SnippetsHelper::getNamespace()
                ->get('adapters/articles_no_ids', 'Can not read articles without ids.');
            throw new \Exception($message);
        }

        if (!$columns && empty($columns)) {
            $message = SnippetsHelper::getNamespace()
                ->get('adapters/articles_no_column_names', 'Can not read articles without column names.');
            throw new \Exception($message);
        }


        //articles
        $articleBuilder = $this->getArticleBuilder($columns['article'], $ids);

        $articles = $articleBuilder->getQuery()->getResult();

        $result['article'] = DbAdapterHelper::decodeHtmlEntities($articles);

        //prices
        $columns['price'] = array_merge(
            $columns['price'], array('customerGroup.taxInput as taxInput', 'articleTax.tax as tax')
        );

        $priceBuilder = $this->getPriceBuilder($columns['price'], $ids);

        $result['price'] = $priceBuilder->getQuery()->getResult();

        foreach ($result['price'] as &$record) {
            if ($record['taxInput']) {
                $record['price'] = round($record['price'] * (100 + $record['tax']) / 100, 2);
                $record['pseudoPrice'] = round($record['pseudoPrice'] * (100 + $record['tax']) / 100, 2);
            } else {
                $record['price'] = round($record['price'], 2);
                $record['pseudoPrice'] = round($record['pseudoPrice'], 2);
            }

            if ($record['basePrice']) {
                $record['basePrice'] = round($record['basePrice'], 2);
            }

            if (!$record['inStock']) {
                $record['inStock'] = '0';
            }
        }

        //images
        $imageBuilder = $this->getImageBuilder($columns['image'], $ids);
        $tempImageResult = $imageBuilder->getQuery()->getResult();
        foreach ($tempImageResult as &$tempImage) {
            $tempImage['imageUrl'] = Shopware()->Container()->get('shopware_media.media_service')->getUrl($tempImage['imageUrl']);
        }
        $result['image'] = $tempImageResult;

        //filter values
        $propertyValuesBuilder = $this->getPropertyValueBuilder($columns['propertyValues'], $ids);
        $result['propertyValue'] = $propertyValuesBuilder->getQuery()->getResult();

        //configurator
        $configBuilder = $this->getConfiguratorBuilder($columns['configurator'], $ids);
        $result['configurator'] = $configBuilder->getQuery()->getResult();

        //similar
        $similarsBuilder = $this->getSimilarBuilder($columns['similar'], $ids);
        $result['similar'] = $similarsBuilder->getQuery()->getResult();

        //accessories
        $accessoryBuilder = $this->getAccessoryBuilder($columns['accessory'], $ids);
        $result['accessory'] = $accessoryBuilder->getQuery()->getResult();

        //categories
        $result['category'] = $this->prepareCategoryExport($ids, $columns['category']);

        $result['translation'] = $this->prepareTranslationExport($ids);

        return $result;
    }

    public function prepareCategoryExport($ids, $categoryColumns)
    {
        $mappedArticleIds = $this->getArticleIdsByDetailIds($ids);

        $categoryBuilder = $this->getCategoryBuilder($categoryColumns, $mappedArticleIds);
        $articleCategories = $categoryBuilder->getQuery()->getResult();

        $categoryMapper = $this->getAssignedCategoryNames($articleCategories);

        //convert path
        foreach($articleCategories as &$pathIds) {
            $pathIds['categoryPath'] = $this->generatePath($pathIds, $categoryMapper);
        }

        return $articleCategories;
    }

    /**
     * Returns article ids
     * @param $detailIds
     * @return array
     */
    protected function getArticleIdsByDetailIds($detailIds)
    {
        $articleIds = $this->getManager()->createQueryBuilder()
            ->select('article.id')
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->where('variant.id IN (:ids)')
            ->setParameter('ids', $detailIds)
            ->groupBy('article.id');

        $mappedArticleIds = array_map(
            function($item) {
                return $item['id'];
            },
            $articleIds->getQuery()->getResult()
        );

        return $mappedArticleIds;
    }

    /**
     * Collects and creates a helper mapper for category path
     *
     * @param array $categories
     * @return array
     */
    protected function getAssignedCategoryNames($categories)
    {
        $categoryIds = array();
        foreach($categories as $category) {
            if (!empty($category['categoryId'])) {
                $categoryIds[] = (string) $category['categoryId'];
            }

            if (!empty($category['categoryPath'])) {
                $catPath = explode('|', $category['categoryPath']);
                $categoryIds = array_merge($categoryIds, $catPath);
            }
        }

        //only unique ids
        $categoryIds = array_unique($categoryIds);

        //removes empty value
        $categoryIds = array_filter($categoryIds);

        $categoriesNames = $this->getManager()->createQueryBuilder()
            ->select(array('category.id, category.name'))
            ->from('Shopware\Models\Category\Category', 'category')
            ->where('category.id IN (:ids)')
            ->setParameter('ids', $categoryIds)
            ->getQuery()->getResult();

        $names = array();
        foreach($categoriesNames as $name) {
            $names[$name['id']] = $name['name'];
        }

        return $names;
    }

    /**
     * @param array $category contains category data
     * @param array $mapper contains categories' names
     * @return string converted path
     */
    protected function generatePath($category, $mapper)
    {
        $ids = array();
        if (!empty($category['categoryPath'])) {
            foreach(explode('|', $category['categoryPath']) as $id) {
                $ids[] = $mapper[$id];
            }
        }
        krsort($ids);

        if (!empty($category['categoryId'])) {
            $ids[] = $mapper[$category['categoryId']];
        }

        $ids = array_filter($ids);

        $path = implode('->', $ids);

        return $path;
    }

    public function prepareTranslationExport($ids)
    {
        //translations
        $translationVariantColumns = 'article.id as articleId, variant.id as variantId, variant.kind, ct.objectdata, ct.objectlanguage as languageId';
        $articleDetailIds = implode(',', $ids);

        $sql = "SELECT $translationVariantColumns FROM `s_articles_details` AS variant

                LEFT JOIN s_articles AS article
                ON article.id = variant.articleID

                LEFT JOIN s_core_translations AS ct
                ON variant.id = ct.objectkey AND objecttype = 'variant'

                WHERE variant.id IN ($articleDetailIds)
                ORDER BY languageId ASC
                ";

        $translations = $this->getDb()->query($sql)->fetchAll();

        $shops = $this->getShops();

        //removes default language
        unset($shops[0]);

        if (!empty($translations)) {

            $translationFields = array(
                "txtArtikel" => "name",
                "txtzusatztxt" => "additionalText",
                "metaTitle" => "metaTitle",
                "txtshortdescription" => "description",
                "txtlangbeschreibung" => "descriptionLong",
                "txtkeywords" => "keywords",
                "txtpackunit" => "packUnit"
            );

            //attributes
            $attributes =  $this->getTranslationAttr();

            if ($attributes){
                foreach ($attributes as $attr){
                    $translationFields[$attr['name']] = $attr['name'];
                }
            }

            $rows = array();
            foreach ($translations as $index => $record) {
                $articleId = $record['articleId'];
                $variantId = $record['variantId'];
                $languageId = $record['languageId'];
                $kind = $record['kind'];
                $rows[$variantId]['helper']['articleId'] = $articleId;
                $rows[$variantId]['helper']['variantKind'] = $kind;
                $rows[$variantId][$languageId]['articleId'] = $articleId;
                $rows[$variantId][$languageId]['variantId'] = $variantId;
                $rows[$variantId][$languageId]['languageId'] = $languageId;
                $rows[$variantId][$languageId]['variantKind'] = $kind;

                $objectdata = unserialize($record['objectdata']);

                if (!empty($objectdata)) {
                    foreach ($objectdata as $key => $value) {
                        if (isset($translationFields[$key])) {
                            $rows[$variantId][$languageId][$translationFields[$key]] = $value;
                        }
                    }
                }
            }
        }

        $result = array();

        foreach ($rows as $vId => $row) {
            $count = count($row) -1;
            if (count($shops) !== $count) {
                foreach ($shops as $shop) {
                    $shopId = $shop->getId();
                    if (isset($row[$shopId])) {
                        $result[] = $row[$shopId];
                    } else {
                        $result[] = array(
                            'articleId' => $row['helper']['articleId'],
                            'variantId' => $vId,
                            'languageId' => (string) $shopId,
                            'variantKind' => $row['helper']['variantKind'],
                        );
                        $index = count($result) - 1;
                        foreach($translationFields as $field){
                            $result[$index][$field] = '';
                        }
                    }
                }
            } else {
                unset($row['helper']);
                foreach ($row as $value) {
                    $result[] = $value;
                }
            }
        }

        $translationArticleColumns = 'article.id as articleId, ct.objectdata, ct.objectlanguage as languageId';

        $sql = "SELECT $translationArticleColumns FROM `s_articles_details` AS variant

                INNER JOIN s_articles AS article
                ON article.id = variant.articleID

                LEFT JOIN s_core_translations AS ct
                ON variant.id = ct.objectkey

                WHERE variant.id IN ($articleDetailIds) AND objecttype = 'article'
                GROUP BY ct.id
                ";
        $articles = $this->getDb()->query($sql)->fetchAll();


        foreach ($result as $index => $translation){
            foreach($articles as $article){
                //the translation for the main variant is coming
                //from article translations
                if ($translation['variantKind'] == 1
                    && $translation['articleId'] === $article['articleId']
                    && $translation['languageId'] === $article['languageId']){

                    $serializeData = unserialize($article['objectdata']);
                    foreach($translationFields as $key => $field){
                        $result[$index][$field] = $serializeData[$key];
                    }
                } else if($translation['articleId'] === $article['articleId'] && $translation['languageId'] === $article['languageId']){
                    $data = unserialize($article['objectdata']);
                    $result[$index]['name'] = $data['txtArtikel'];
                    $result[$index]['description'] = $data['txtshortdescription'];
                    $result[$index]['descriptionLong'] = $data['txtlangbeschreibung'];
                    $result[$index]['metaTitle'] = $data['metaTitle'];
                    $result[$index]['keywords'] = $data['txtkeywords'];
                }
            }
        }

        return $result;
    }

    public function getShops()
    {
        $shops = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop')->findAll();

        return $shops;
    }

    /**
     * Returns default columns
     * @return array
     */
    public function getDefaultColumns()
    {
        $otherColumns = array(
            'variantsUnit.unit as unit',
            'articleEsd.file as esd',
        );

        $columns['article'] = array_merge(
            $this->getArticleColumns(), $otherColumns
        );

        $columns['price'] = $this->getPriceColumns();
        $columns['image'] = $this->getImageColumns();
        $columns['propertyValues'] = $this->getPropertyValueColumns();
        $columns['similar'] = $this->getSimilarColumns();
        $columns['accessory'] = $this->getAccessoryColumns();
        $columns['configurator'] = $this->getConfiguratorColumns();
        $columns['category'] = $this->getCategoryColumns();
        $columns['translation'] = $this->getTranslationColumns();

        return $columns;
    }

    /**
     * Return list with default values for fields which are empty or don't exists
     *
     * @return array
     */
    private function getDefaultValues()
    {
        return $this->defaultValues;
    }

    /**
     * Set default values for fields which are empty or don't exists
     *
     * @param array $values default values for nodes
     */
    public function setDefaultValues($values)
    {
        $this->defaultValues = $values;
    }

    private function run($records)
    {
        Shopware()->Models()->getConnection()->beginTransaction();

        try {
            $this->performImport($records);
            Shopware()->Models()->getConnection()->commit();
        } catch(\Exception $e) {
            Shopware()->Models()->getConnection()->rollBack();
            throw $e;
        }
    }

    private function performImport($records)
    {
        $manager = $this->getManager();
        $articleWriter = new ArticleWriter();
        $pricesWriter = new PriceWriter();
        $categoryWriter = new CategoryWriter();
        $configuratorWriter = new ConfiguratorWriter();
        $translationWriter = new TranslationWriter();
        $propertyWriter = new PropertyWriter();
        $relationWriter = new RelationWriter($this);
        $imageWriter = new ImageWriter($this);

        $defaultValues = $this->getDefaultValues();

        foreach ($records['article'] as $index => $article) {
            try {
                $manager->getConnection()->beginTransaction();

                list($articleId, $articleDetailId, $mainDetailId) = $articleWriter->write($article, $defaultValues);

                $processedFlag = isset($article['processed']) && $article['processed'] == 1;

                /**
                 * Only processed data will be imported
                 */
                if (!$processedFlag) {
                    $pricesWriter->write(
                        $articleId,
                        $articleDetailId,
                        array_filter(
                            $records['price'],
                            function ($price) use ($index) {
                                return $price['parentIndexElement'] == $index;
                            }
                        )
                    );

                    $categoryWriter->write(
                        $articleId,
                        array_filter(
                            $records['category'],
                            function ($category) use ($index) {
                                return $category['parentIndexElement'] == $index && ($category['categoryId'] || $category['categoryPath']);
                            }
                        )
                    );

                    $configuratorWriter->write(
                        $articleId,
                        $articleDetailId,
                        $mainDetailId,
                        array_filter(
                            $records['configurator'],
                            function ($configurator) use ($index) {
                                return $configurator['parentIndexElement'] == $index;
                            }
                        )
                    );

                    $propertyWriter->write(
                        $articleId,
                        $article['orderNumber'],
                        array_filter(
                            $records['propertyValue'],
                            function ($property) use ($index, $mainDetailId, $articleDetailId) {
                                return $property['parentIndexElement'] == $index && $mainDetailId == $articleDetailId;
                            }
                        )
                    );

                    $translationWriter->write(
                        $articleId,
                        $articleDetailId,
                        $mainDetailId,
                        array_filter(
                            $records['translation'],
                            function ($translation) use ($index) {
                                return $translation['parentIndexElement'] == $index;
                            }
                        )
                    );
                }

                /**
                 * Processed and unprocessed data will be imported
                 */
                if ($processedFlag) {
                    $article['mainNumber'] = $article['orderNumber'];
                }

                $relationWriter->write(
                    $articleId,
                    $article['mainNumber'],
                    array_filter(
                        $records['accessory'],
                        function ($accessory) use ($index, $mainDetailId, $articleDetailId) {
                            return $accessory['parentIndexElement'] == $index && $mainDetailId == $articleDetailId;
                        }
                    ),
                    'accessory',
                    $processedFlag
                );

                $relationWriter->write(
                    $articleId,
                    $article['mainNumber'],
                    array_filter(
                        $records['similar'],
                        function ($similar) use ($index, $mainDetailId, $articleDetailId) {
                            return $similar['parentIndexElement'] == $index && $mainDetailId == $articleDetailId;
                        }
                    ),
                    'similar',
                    $processedFlag
                );

                $imageWriter->write(
                    $articleId,
                    $article['mainNumber'],
                    array_filter(
                        $records['image'],
                        function ($image) use ($index) {
                            return $image['parentIndexElement'] == $index;
                        }
                    )
                );

                $manager->getConnection()->commit();
            } catch (AdapterException $e) {
                $manager->getConnection()->rollBack();
                $message = $e->getMessage();
                $this->saveMessage($message);
            }
        }
    }

    /**
     * Writes articles into the database
     *
     * @param array $records
     * @throws \Exception
     * @throws AdapterException
     */
    public function write($records)
    {
        //articles
        if (empty($records['article'])) {
            $message = SnippetsHelper::getNamespace()->get(
                'adapters/articles/no_records',
                'No article records were found.'
            );
            throw new \Exception($message);
        }

        $records = Shopware()->Events()->filter(
            'Shopware_Components_SwagImportExport_DbAdapters_ArticlesDbAdapter_Write',
            $records,
            array('subject' => $this)
        );

        $this->performImport($records);
    }

    public function getShop($id)
    {
        $shop = $this->getManager()->find('Shopware\Models\Shop\Shop', $id);
        if (!$shop) {
            $message = SnippetsHelper::getNamespace()
                ->get('adapters/articles/no_shop_id', 'Shop by id %s not found');
            throw new AdapterException(sprintf($message, $id));
        }

        return $shop;
    }

    public function getSections()
    {
        return array(
            array('id' => 'article', 'name' => 'article'),
            array('id' => 'price', 'name' => 'price'),
            array('id' => 'image', 'name' => 'image'),
            array('id' => 'propertyValue', 'name' => 'propertyValue'),
            array('id' => 'similar', 'name' => 'similar'),
            array('id' => 'accessory', 'name' => 'accessory'),
            array('id' => 'configurator', 'name' => 'configurator'),
            array('id' => 'category', 'name' => 'category'),
            array('id' => 'translation', 'name' => 'translation')
        );
    }

    public function getArticleColumns()
    {
        return array_merge($this->getArticleVariantColumns(), $this->getVariantColumns());
    }

    public function getArticleVariantColumns()
    {
        return array(
            'article.id as articleId',
            'article.name as name',
            'article.description as description',
            'article.descriptionLong as descriptionLong',
            "DATE_FORMAT(article.added, '%Y-%m-%d') as date",
//            'article.active as active',
            'article.pseudoSales as pseudoSales',
            'article.highlight as topSeller',
            'article.metaTitle as metaTitle',
            'article.keywords as keywords',
            "DATE_FORMAT(article.changed, '%Y-%m-%d %H:%i:%s') as changeTime",
            'article.priceGroupId as priceGroupId',
            'article.priceGroupActive as priceGroupActive',
            'article.lastStock as lastStock',
            'article.crossBundleLook as crossBundleLook',
            'article.notification as notification',
            'article.template as template',
            'article.mode as mode',
            'article.availableFrom as availableFrom',
            'article.availableTo as availableTo',
            'supplier.id as supplierId',
            'supplier.name as supplierName',
            'articleTax.id as taxId',
            'articleTax.tax as tax',
            'filterGroup.id as filterGroupId',
            'filterGroup.name as filterGroupName',
        );
    }

    public function getArticleAttributes()
    {
        $stmt = $this->getDb()->query("SHOW COLUMNS FROM `s_articles_attributes`");
        $columns = $stmt->fetchAll();

        $attributes = array();
        foreach ($columns as $column) {
            if ($column['Field'] !== 'id' && $column['Field'] !== 'articleID' && $column['Field'] !== 'articledetailsID') {
                $attributes[] = $column['Field'];
            }
        }

        if ($attributes) {
            $prefix = 'attribute';
            $attributesSelect = array();
            foreach ($attributes as $attribute) {
                //underscore to camel case
                //exmaple: underscore_to_camel_case -> underscoreToCamelCase

                $attr = preg_replace("/\_(.)/e", "strtoupper('\\1')", $attribute);

                $attributesSelect[] = sprintf('%s.%s as attribute%s', $prefix, $attr, ucwords($attr));
            }
        }

        return $attributesSelect;
    }

    public function getColumns($section)
    {
        $method = 'get' . ucfirst($section) . 'Columns';

        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        return false;
    }

    public function getParentKeys($section)
    {
        switch ($section) {
            case 'article':
                return array(
                    'article.id as articleId',
                    'variant.id as variantId',
                    'variant.number as orderNumber',
                );
            case 'price':
                return array(
                    'prices.articleDetailsId as variantId',
                );
            case 'propertyValue':
                return array(
                    'article.id as articleId',
                );
            case 'similar':
                return array(
                    'article.id as articleId',
                );
            case 'accessory':
                return array(
                    'article.id as articleId',
                );
            case 'image':
                return array(
                    'article.id as articleId',
                );
            case 'configurator':
                return array(
                    'variant.id as variantId',
                );
            case 'category':
                return array(
                    'article.id as articleId',
                );
            case 'translation':
                return array(
                    'variant.id as variantId',
                );
        }
    }

    public function getVariantColumns()
    {
        $columns = array(
            'variant.id as variantId',
            'variant.number as orderNumber',
            'mv.number as mainNumber',
            'variant.kind as kind',
            'variant.additionalText as additionalText',
            'variant.inStock as inStock',
            'variant.active as active',
            'variant.stockMin as stockMin',
            'variant.weight as weight',
            'variant.position as position',
            'variant.width as width',
            'variant.height as height',
            'variant.len as length',
            'variant.ean as ean',
            'variant.unitId as unitId',
            'variant.purchaseSteps as purchaseSteps',
            'variant.minPurchase as minPurchase',
            'variant.maxPurchase as maxPurchase',
            'variant.purchaseUnit as purchaseUnit',
            'variant.referenceUnit as referenceUnit',
            'variant.packUnit as packUnit',
            "DATE_FORMAT(variant.releaseDate, '%Y-%m-%d') as releaseDate",
            'variant.shippingTime as shippingTime',
            'variant.shippingFree as shippingFree',
            'variant.supplierNumber as supplierNumber',
        );

        // Attributes
        $attributesSelect = $this->getArticleAttributes();

        if ($attributesSelect && !empty($attributesSelect)) {
            $columns = array_merge($columns, $attributesSelect);
        }

        return $columns;
    }

    public function getPriceColumns()
    {
        return array(
            'prices.articleDetailsId as variantId',
            'prices.articleId as articleId',
            'prices.price as price',
            'prices.pseudoPrice as pseudoPrice',
            'prices.basePrice as basePrice',
            'prices.customerGroupKey as priceGroup',
        );
    }

    public function getImageColumns()
    {
        return array(
            'images.id as id',
            'images.articleId as articleId',
            'images.articleDetailId as variantId',
            'images.path as path',
            "CONCAT('media/image/', images.path, '.', images.extension) as imageUrl",
            'images.main as main',
            'images.mediaId as mediaId',
            ' \'1\' as thumbnail'
        );
    }

    public function getPropertyValueColumns()
    {
        return array(
            'article.id as articleId',
            'propertyGroup.name as propertyGroupName',
            'propertyValues.id as propertyValueId',
            'propertyValues.value as propertyValueName',
            'propertyValues.position as propertyValuePosition',
            'propertyValues.valueNumeric as propertyValueNumeric',
            'propertyOptions.name as propertyOptionName',
        );
    }

    public function getSimilarColumns()
    {
        return array(
            'similar.id as similarId',
            'similarDetail.number as ordernumber',
            'article.id as articleId',
        );
    }

    public function getAccessoryColumns()
    {
        return array(
            'accessory.id as accessoryId',
            'accessoryDetail.number as ordernumber',
            'article.id as articleId',
        );
    }

    public function getConfiguratorColumns()
    {
        return array(
            'variant.id as variantId',
            'configuratorOptions.id as configOptionId',
            'configuratorOptions.name as configOptionName',
            'configuratorOptions.position as configOptionPosition',
            'configuratorGroup.id as configGroupId',
            'configuratorGroup.name as configGroupName',
            'configuratorGroup.description as configGroupDescription',
            'configuratorSet.id as configSetId',
            'configuratorSet.name as configSetName',
            'configuratorSet.type as configSetType',
        );
    }

    public function getCategoryColumns()
    {
        return array(
            'categories.id as categoryId',
            'categories.path as categoryPath',
            'article.id as articleId',
        );
    }

    public function getTranslationColumns()
    {
        $columns = array(
            'article.id as articleId',
            'variant.id as variantId',
            'translation.objectlanguage as languageId',
            'translation.name as name',
            'translation.keywords as keywords',
            'translation.metaTitle as metaTitle',
            'translation.description as description',
            'translation.description_long as descriptionLong',
            'translation.additionalText as additionalText',
            'translation.packUnit as packUnit',
        );

        $attributes = $this->getTranslationAttr();

        if($attributes){
            foreach ($attributes as $attr){
                $columns[] = $attr['name'];
            }
        }

        return $columns;
    }

    public function getTranslationAttr()
    {
        $elementBuilder = $this->getElementBuilder();

        return $elementBuilder->getQuery()->getArrayResult();
    }

    public function saveMessage($message)
    {
        $errorMode = Shopware()->Config()->get('SwagImportExportErrorMode');

        if ($errorMode === false) {
            throw new \Exception($message);
        }

        $this->setLogMessages($message);
    }

    public function getLogMessages()
    {
        return $this->logMessages;
    }

    public function setLogMessages($logMessages)
    {
        $this->logMessages[] = $logMessages;
    }

    /**
     * Returns/Creates mapper depend on the key
     * Exmaple: articles, variants, prices ...
     *
     * @param string $key
     * @return array
     */
    public function getMap($key)
    {
        $property = $key . 'Map';
        if ($this->{$property} === null) {
            $method = 'get' . ucfirst($key) . 'Columns';
            if (method_exists($this, $method)) {
                $columns = $this->{$method}();

                foreach ($columns as $column) {
                    $map = DataHelper::generateMappingFromColumns($column);
                    $this->{$property}[$map[0]] = $map[1];
                }
            }
        }

        return $this->{$property};
    }

    public function getCategoryIdCollection()
    {
        return $this->categoryIdCollection;
    }

    public function setCategoryIdCollection($categoryIdCollection)
    {
        $this->categoryIdCollection[] = $categoryIdCollection;
    }

    public function saveUnprocessedData($profileName, $type, $articleNumber, $data)
    {
        $this->saveArticleData($articleNumber);

        $this->setUnprocessedData($profileName, $type, $data);
    }

    /**
     * This data is for matching similars and accessories
     *
     * @param $articleNumber
     */
    protected function saveArticleData($articleNumber)
    {
        $tempData = $this->getTempData();

        if (isset($tempData[$articleNumber])) {
            return;
        }

        $this->setTempData($articleNumber);

        $articleData = array(
            'articleId' => $articleNumber,
            'orderNumber' => $articleNumber,
//            'mainNumber' => $articleNumber, //TODO: check if this could be used
            'processed' => 1
        );

        $this->setUnprocessedData('articles', 'article', $articleData);
    }

    public function getUnprocessedData()
    {
        return $this->unprocessedData;
    }

    public function setUnprocessedData($profileName, $type, $data)
    {
        $this->unprocessedData[$profileName][$type][] = $data;
    }

    public function getTempData()
    {
        return $this->tempData;
    }

    public function setTempData($tempData)
    {
        $this->tempData[$tempData] = $tempData;
    }

    /**
     * Returns article repository
     *
     * @return \Shopware\Models\Article\Article
     */
    public function getRepository()
    {
        if ($this->repository === null) {
            $this->repository = $this->getManager()->getRepository('Shopware\Models\Article\Article');
        }

        return $this->repository;
    }

    /**
     * Returns deatil repository
     *
     * @return \Shopware\Models\Article\Detail
     */
    public function getVariantRepository()
    {
        if ($this->variantRepository === null) {
            $this->variantRepository = $this->getManager()->getRepository('Shopware\Models\Article\Detail');
        }

        return $this->variantRepository;
    }

    /*
     * @return Shopware\Components\Model\ModelManager
     */
    public function getManager()
    {
        if ($this->manager === null) {
            $this->manager = Shopware()->Models();
        }

        return $this->manager;
    }

    public function getDb()
    {
        if ($this->db === null){
            $this->db = Shopware()->Db();
        }

        return $this->db;
    }

    public function getTranslationPropertyGroup($articleDetailIds)
    {
        $sql = "SELECT filter.name as baseName,
                ct.objectkey, ct.objectdata, ct.objectlanguage as propertyLanguageId
                FROM s_articles_details AS articleDetails

                INNER JOIN s_articles AS article
                ON article.id = articleDetails.articleID

                LEFT JOIN s_filter_articles AS fa
                ON fa.articleID = article.id

                LEFT JOIN s_filter_values AS fv
                ON fv.id = fa.valueID

                LEFT JOIN s_filter_relations AS fr
                ON fr.optionID = fv.optionID

                LEFT JOIN s_filter AS filter
                ON filter.id = fr.groupID

                LEFT JOIN s_core_translations AS ct
                ON ct.objectkey = filter.id

                WHERE articleDetails.id IN ($articleDetailIds) AND ct.objecttype = 'propertygroup'
                GROUP BY ct.id
                ";

        return $this->getDb()->query($sql)->fetchAll();
    }

    public function getTranslationPropertyOption($articleDetailIds)
    {
        $sql = "SELECT fo.name as baseName,
                ct.objectkey, ct.objectdata, ct.objectlanguage as propertyLanguageId
                FROM s_articles_details AS articleDetails

                INNER JOIN s_articles AS article
                ON article.id = articleDetails.articleID

                LEFT JOIN s_filter_articles AS fa
                ON fa.articleID = article.id

                LEFT JOIN s_filter_values AS fv
                ON fv.id = fa.valueID

                LEFT JOIN s_filter_options AS fo
                ON fo.id = fv.optionID

                LEFT JOIN s_core_translations AS ct
                ON ct.objectkey = fo.id

                WHERE articleDetails.id IN ($articleDetailIds) AND ct.objecttype = 'propertyoption'
                GROUP BY ct.id
                ";

        return $this->getDb()->query($sql)->fetchAll();
    }

    /**
     * Collects recursively category ids
     *
     * @param \Shopware\Models\Category\Category $categoryModel
     * @return
     */
    protected function collectCategoryIds($categoryModel)
    {
        $categoryId = $categoryModel->getId();
        $this->setCategoryIdCollection($categoryId);
        $categories = $categoryModel->getChildren();

        if (!$categories) {
            return;
        }

        foreach ($categories as $category) {
            $this->collectCategoryIds($category);
        }

        return;
    }

    public function getArticleBuilder($columns, $ids)
    {
        $articleBuilder = $this->getManager()->createQueryBuilder();
        $articleBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftJoin('Shopware\Models\Article\Detail', 'mv', \Doctrine\ORM\Query\Expr\Join::WITH, 'mv.articleId=article.id AND mv.kind=1')
            ->leftJoin('variant.attribute', 'attribute')
            ->leftJoin('article.tax', 'articleTax')
            ->leftJoin('article.supplier', 'supplier')
            ->leftJoin('article.propertyGroup', 'filterGroup')
            ->leftJoin('article.esds', 'articleEsd')
            ->leftJoin('variant.unit', 'variantsUnit')
            ->where('variant.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy("variant.kind");

        return $articleBuilder;
    }

    public function getPriceBuilder($columns, $ids)
    {
        $priceBuilder = $this->getManager()->createQueryBuilder();
        $priceBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftJoin('variant.prices', 'prices')
            ->leftJoin('prices.customerGroup', 'customerGroup')
            ->leftJoin('article.tax', 'articleTax')
            ->where('variant.id IN (:ids)')
            ->setParameter('ids', $ids);

        return $priceBuilder;
    }

    public function getImageBuilder($columns, $ids)
    {
        $imageBuilder = $this->getManager()->createQueryBuilder();
        $imageBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftjoin('article.images', 'images')
            ->where('variant.id IN (:ids)')
            ->andWhere('variant.kind = 1')
            ->andWhere('images.id IS NOT NULL')
            ->setParameter('ids', $ids);

        return $imageBuilder;
    }

    public function getPropertyValueBuilder($columns, $ids)
    {
        $propertyValueBuilder = $this->getManager()->createQueryBuilder();
        $propertyValueBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftjoin('article.propertyGroup', 'propertyGroup')
            ->leftjoin('article.propertyValues', 'propertyValues')
            ->leftjoin('propertyValues.option', 'propertyOptions')
            ->where('variant.id IN (:ids)')
            ->andWhere('variant.kind = 1')
            ->andWhere('propertyValues.id IS NOT NULL')
            ->setParameter('ids', $ids);

        return $propertyValueBuilder;
    }

    public function getConfiguratorBuilder($columns, $ids)
    {
        $configBuilder = $this->getManager()->createQueryBuilder();
        $configBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftjoin('variant.configuratorOptions', 'configuratorOptions')
            ->leftjoin('configuratorOptions.group', 'configuratorGroup')
            ->leftjoin('article.configuratorSet', 'configuratorSet')
            ->where('variant.id IN (:ids)')
            ->andWhere('configuratorOptions.id IS NOT NULL')
            ->andWhere('configuratorGroup.id IS NOT NULL')
            ->andWhere('configuratorSet.id IS NOT NULL')
            ->setParameter('ids', $ids);

        return $configBuilder;
    }

    public function getSimilarBuilder($columns, $ids)
    {
        $similarBuilder = $this->getManager()->createQueryBuilder();
        $similarBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftjoin('article.similar', 'similar')
            ->leftjoin('similar.details', 'similarDetail')
            ->where('variant.id IN (:ids)')
            ->andWhere('variant.kind = 1')
            ->andWhere('similarDetail.kind = 1')
            ->andWhere('similar.id IS NOT NULL')
            ->setParameter('ids', $ids);

        return $similarBuilder;
    }

    public function getAccessoryBuilder($columns, $ids)
    {
        $accessoryBuilder = $this->getManager()->createQueryBuilder();
        $accessoryBuilder->select($columns)
            ->from('Shopware\Models\Article\Detail', 'variant')
            ->join('variant.article', 'article')
            ->leftjoin('article.related', 'accessory')
            ->leftjoin('accessory.details', 'accessoryDetail')
            ->where('variant.id IN (:ids)')
            ->andWhere('variant.kind = 1')
            ->andWhere('accessoryDetail.kind = 1')
            ->andWhere('accessory.id IS NOT NULL')
            ->setParameter('ids', $ids);

        return $accessoryBuilder;
    }

    public function getCategoryBuilder($columns, $ids)
    {
        $categoryBuilder = $this->getManager()->createQueryBuilder();
        $categoryBuilder->select($columns)
            ->from('Shopware\Models\Article\Article', 'article')
            ->leftjoin('article.categories', 'categories')
            ->where('article.id IN (:ids)')
            ->andWhere('categories.id IS NOT NULL')
            ->setParameter('ids', $ids);

        return $categoryBuilder;
    }

    public function getElementBuilder()
    {
        $repository = $this->getManager()->getRepository('Shopware\Models\Article\Element');

        $builder = $repository->createQueryBuilder('attribute');
        $builder->andWhere('attribute.translatable = 1');
        $builder->orderBy('attribute.position');

        return $builder;
    }
}