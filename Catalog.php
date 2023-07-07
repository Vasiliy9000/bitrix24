<?php

namespace Advina\Bitrix\Service;

use Advina\CRest;
use Advina\Gate\Ajax;
use Advina\Gate\Result;
use Advina\Lib;
use Advina\SUtils;
use Bitrix\Iblock\Elements\ElementChatbotOLTable;
use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Loader;
use DateInterval;
use DateTime;
use stdClass;
use Bitrix\Highloadblock as HL;
class Catalog extends Base
{
    public const entityTypeIds = [
        'Декларации'  => 142,
        'Отказные'    => 148,
        'Сертификаты' => 156,
        'Протоколы'   => 164,
    ];

    /**
     * Список методов обработчика.
     *
     * @var array
     * @uses smartProcessHandler
     */
    protected const METHODS = [ // Метод для удаления и добавления
        'smartProcessHandler' => 'smartProcessHandler',
        'smartProcessDeleteHandler' => 'smartProcessDeleteHandler',
    ];

    protected const ID_FIELD_GOODS = [ // дополнительные поля в товарах для привязки к сделкам
        'Декларации'  => 'ufCrm7_1688472280', // это на самом деле UF_CRM_7_1688472280
        'Отказные'    => 'ufCrm9_1687933465',
        'Сертификаты' => 'ufCrm5_1688472409',
        'Протоколы'   => 'ufCrm11_1688472502',
    ];

    protected const SECTION_IDS = [ // данные секции это типа вид товара
        'Декларации'  => 51,
        'Отказные'    => 49,
        'Сертификаты' => 17,
        'Протоколы'   => 21,
    ];

    protected function smartProcessHandler(): Result
    {
        if (!$this->_checkGetParamDefined('deal', 'Deal')) { // приходит ли нам параметр
            return $this->result;
        }

        $entityType = $this->data->_get['entityType']; // тип сущности ( помоему там литера должна быть типа как D )
        $smart_id = (int)$this->data->_get['ID']; // id смарт процесса
        $entityTypeId = static::entityTypeIds[$entityType]; // id типа смарт-процесса

        $itemGet = CRest::call('crm.item.get',[ // попадаем в нужный смарт процесс (по моему)
            'entityTypeId'  =>  $entityTypeId,
            'id'    => $smart_id,
        ]);

        $dealId = $itemGet['result']['item']['parentId2'];
        $goods = $itemGet['result']['item'][self::ID_FIELD_GOODS[$entityType]];
        $name = $itemGet['result']['item']['title'];
        $summa = $itemGet['result']['item']['opportunity'];

        if (!empty($goods)) { // если товар есть, то обновим его параметрами сверху
            $product_id = $goods;
            CRest::call('catalog.product.update', [
                'id' => $product_id,
                'fields' => [
                    'property317' => $entityTypeId,
                    'property315' => $smart_id,
                    'property321' => $dealId,
                    'name' => $name,
                ],
            ]);

            $rowList = CRest::call('crm.item.productrow.list', [ // добавим строку в сделку для того чтоб туда положить товар
                'filter' => [
                    '=ownerType' => 'D',
                    '=ownerId' => $this->deal['ID'], //
                    'productId' => $product_id,
                ]
            ]);

            foreach ($rowList['result']['productRows'] as $row) {  //  цикл пробежиться по строкам делки
                CRest::call('crm.item.productrow.update', [
                    'id' => $row['id'],
                    'fields' => [
                        'productName' => $name,
                        'productId' => $product_id,
                        'price' => $summa,
                        'quantity' => 1,
                    ],
                ]);
            }
        } else {  // а если товара нет то
            $productAdd = CRest::call('catalog.product.add', [
                    'fields' => [
                        'iblockId' => 15,
                        'iblockSectionId' => static::SECTION_IDS[$entityType],
                        'name' => $name,
                        'property317' => $entityTypeId,
                        'property315' => $smart_id,
                        'property321' => $dealId,
                    ],
                ]
            );

            $product_id = (int)$productAdd['result']['element']['id'] ?? null;

            CRest::call('crm.item.update', [ //Метод обновит элемент с идентификатором id смарт-процесса с идентификатором entityTypeId.
                'entityTypeId' => $entityTypeId,
                'id' => $smart_id,
                'fields' => [
                    self::ID_FIELD_GOODS[$entityType] => $product_id,
                ],
                'params' => [
                    'REGISTER_SONET_EVENT' => 'N',
                ]
            ]);

            CRest::call('crm.item.productrow.add', [ // Метод создает новую товарную позицию с полями fields. При этом новая товарная позиция привязывается к элементу CRM, указанному в полях ownerType и ownerId.
                    'fields' => [
                        'ownerType' => 'D',
                        'ownerId' => $this->deal['ID'],
                        'productName' => $name,
                        'productId' => $product_id,
                        'price' => $summa,
                        'quantity' => 1,
                        'measureCode' => 796,
                    ]
                ]
            );
        }
        {
        return $this->result->set([]);
        }
    }
    protected function smartProcessDeleteHandler(): Result
    {
        CRest::setLog([  // запрос на получение данных
            'REQUEST' => $_REQUEST,
            'GET' => $_GET,
            'POST' => $_POST,
        ],    'productToDelete');

        $smart_id = $this->data->_post['data']['FIELDS']['ID']; // из реквест запроса получем данные
        $entityTypeId = $this->data->_post['data']['FIELDS']['ENTITY_TYPE_ID'];

        $productList = CRest::call('catalog.product.list', [ // фильтруем товары из каталога
           'select' => ['id', 'iblockId', '*', 'property*'],
           'filter' => [
               'iblockId' => 15,
               'property317' => $entityTypeId,
               'property315' => $smart_id,
           ]
        ]);

        $productId = $productList['result']['products'][0]['id'];
        $dealId = $productList['result']['products'][0]['property321']['value'];

        $productRowList = CRest::call('crm.item.productrow.list', [ //находим ид строки в сделки куда кладётся товар
            'select' => ['*'],
            'filter' => [
                '=ownerType' => 'D',
                '=ownerId' => $dealId,
                'productId' => $productId,
            ]
        ]);

        CRest::call('catalog.product.delete', [ // удаляем товар из каталога
                'id' => $productId,
            ]
        );

        CRest::call('crm.item.productrow.delete', [ // удаляем строку где был товар
                'id' => $productRowList['result']['productRows'][0]['id'],
            ]
        );
        {
            return $this->result->set([]);
        }
    }
}
