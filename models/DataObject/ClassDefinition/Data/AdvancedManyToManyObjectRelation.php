<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Model\DataObject\ClassDefinition\Data;

use Pimcore\Db;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element;

/**
 * @method DataObject\Data\ObjectMetadata\Dao getDao()
 */
class AdvancedManyToManyObjectRelation extends ManyToManyObjectRelation implements IdRewriterInterface, PreGetDataInterface, LayoutDefinitionEnrichmentInterface, ClassSavedInterface
{
    use DataObject\Traits\ElementWithMetadataComparisonTrait;
    use DataObject\ClassDefinition\Data\Extension\PositionSortTrait;

    /**
     * @internal
     *
     * @var string|null
     */
    public $allowedClassId;

    /**
     * @internal
     *
     * @var string|null
     */
    public $visibleFields;

    /**
     * @internal
     *
     * @var array
     */
    public $columns = [];

    /**
     * @internal
     *
     * @var string[]
     */
    public $columnKeys = [];

    /**
     * Static type of this element
     *
     * @internal
     *
     * @var string
     */
    public $fieldtype = 'advancedManyToManyObjectRelation';

    /**
     * @internal
     *
     * @var bool
     */
    public $enableBatchEdit = false;

    /**
     * @internal
     *
     * @var bool
     */
    public $allowMultipleAssignments = false;

    /**
     * @internal
     *
     * @var array
     */
    public $visibleFieldDefinitions = [];

    /**
     * {@inheritdoc}
     */
    protected function prepareDataForPersistence($data, $object = null, $params = [])
    {
        $return = [];

        if (is_array($data) && count($data) > 0) {
            $counter = 1;
            foreach ($data as $metaObject) {
                $object = $metaObject->getObject();
                if ($object instanceof DataObject\Concrete) {
                    $return[] = [
                        'dest_id' => $object->getId(),
                        'type' => 'object',
                        'fieldname' => $this->getName(),
                        'index' => $counter,
                    ];
                }
                $counter++;
            }

            return $return;
        } elseif (is_array($data) && count($data) === 0) {
            //give empty array if data was not null
            return [];
        } else {
            //return null if data was null - this indicates data was not loaded
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function loadData(array $data, $container = null, $params = [])
    {
        $list = [
            'dirty' => false,
            'data' => [],
        ];

        if (count($data) > 0) {
            $db = Db::get();
            $targets = [];

            foreach ($data as $relation) {
                $targetId = $relation['dest_id'];
                $targets[] = $targetId;
            }

            $existingTargets = $db->fetchFirstColumn(
                'SELECT o_id FROM objects WHERE o_id IN ('.implode(',', $targets).')'
            );

            foreach ($data as $key => $relation) {
                if ($relation['dest_id']) {
                    $source = DataObject::getById($relation['src_id']);
                    $destinationId = $relation['dest_id'];

                    if (!in_array($destinationId, $existingTargets)) {
                        // destination object does not exist anymore
                        $list['dirty'] = true;

                        continue;
                    }

                    if ($source instanceof DataObject\Concrete) {
                        /** @var DataObject\Data\ObjectMetadata $metaData */
                        $metaData = \Pimcore::getContainer()->get('pimcore.model.factory')
                            ->build(DataObject\Data\ObjectMetadata::class, [
                                'fieldname' => $this->getName(),
                                'columns' => $this->getColumnKeys(),
                                'object' => null,
                            ]);

                        $metaData->_setOwner($container);
                        $metaData->_setOwnerFieldname($this->getName());
                        $metaData->setObjectId($destinationId);

                        $ownertype = $relation['ownertype'] ? $relation['ownertype'] : '';
                        $ownername = $relation['ownername'] ? $relation['ownername'] : '';
                        $position = $relation['position'] ? $relation['position'] : '0';
                        $index = $key + 1;

                        $metaData->load(
                            $source,
                            $relation['dest_id'],
                            $this->getName(),
                            $ownertype,
                            $ownername,
                            $position,
                            $index
                        );

                        $list['data'][] = $metaData;
                    }
                }
            }
        }
        //must return array - otherwise this means data is not loaded
        return $list;
    }

    /**
     * @param mixed $data
     * @param Model\DataObject\AbstractObject|null $object
     * @param array $params
     *
     * @return string|null
     *
     * @throws \Exception
     */
    public function getDataForQueryResource($data, $object = null, $params = [])
    {
        //return null when data is not set
        if (!$data) {
            return null;
        }

        $ids = [];

        if (is_array($data)) {
            foreach ($data as $metaObject) {
                $object = $metaObject->getObject();
                if ($object instanceof DataObject\Concrete) {
                    $ids[] = $object->getId();
                }
            }

            return ',' . implode(',', $ids) . ',';
        }

        throw new \Exception('invalid data passed to getDataForQueryResource - must be array');
    }

    /**
     * @see Data::getDataForEditmode
     *
     * @param array $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return array
     */
    public function getDataForEditmode($data, $object = null, $params = [])
    {
        $return = [];

        $visibleFieldsArray = $this->getVisibleFields() ? explode(',', $this->getVisibleFields()) : [];

        $gridFields = (array)$visibleFieldsArray;

        // add data
        if (is_array($data) && count($data) > 0) {
            foreach ($data as $mkey => $metaObject) {
                $index = $mkey + 1;
                $object = $metaObject->getObject();
                if ($object instanceof DataObject\Concrete) {
                    $columnData = DataObject\Service::gridObjectData($object, $gridFields, null, ['purpose' => 'editmode']);
                    foreach ($this->getColumns() as $c) {
                        $getter = 'get' . ucfirst($c['key']);

                        try {
                            $columnData[$c['key']] = $metaObject->$getter();
                        } catch (\Exception $e) {
                            Logger::debug('Meta column '.$c['key'].' does not exist');
                        }
                    }

                    $columnData['rowId'] = $columnData['id'] . self::RELATION_ID_SEPARATOR . $index . self::RELATION_ID_SEPARATOR . $columnData['type'];

                    $return[] = $columnData;
                }
            }
        }

        return $return;
    }

    /**
     * @see Data::getDataFromEditmode
     *
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param mixed $params
     *
     * @return array|null
     */
    public function getDataFromEditmode($data, $object = null, $params = [])
    {
        //if not set, return null
        if ($data === null || $data === false) {
            return null;
        }

        $relationsMetadata = [];
        if (is_array($data) && count($data) > 0) {
            foreach ($data as $relation) {
                $o = DataObject\Concrete::getById($relation['id']);
                if ($o && $o->getClassName() == $this->getAllowedClassId()) {
                    /** @var DataObject\Data\ObjectMetadata $metaData */
                    $metaData = \Pimcore::getContainer()->get('pimcore.model.factory')
                        ->build('Pimcore\Model\DataObject\Data\ObjectMetadata', [
                            'fieldname' => $this->getName(),
                            'columns' => $this->getColumnKeys(),
                            'object' => $o,
                        ]);
                    $metaData->_setOwner($object);
                    $metaData->_setOwnerFieldname($this->getName());

                    foreach ($this->getColumns() as $c) {
                        $setter = 'set' . ucfirst($c['key']);
                        $value = $relation[$c['key']] ?? null;

                        if ($c['type'] == 'multiselect' && is_array($value)) {
                            $value = implode(',', $value);
                        }

                        $metaData->$setter($value);
                    }

                    $relationsMetadata[] = $metaData;
                }
            }
        }

        //must return array if data shall be set
        return $relationsMetadata;
    }

    /**
     * @param array $data
     * @param null|DataObject\Concrete $object
     * @param mixed $params
     *
     * @return array
     */
    public function getDataFromGridEditor($data, $object = null, $params = [])
    {
        return $this->getDataFromEditmode($data, $object, $params);
    }

    /**
     * @param array|null $data
     * @param DataObject\Concrete|null $object
     * @param array $params
     *
     * @return array
     */
    public function getDataForGrid($data, $object = null, $params = [])
    {
        return $this->getDataForEditmode($data, $object, $params);
    }

    /**
     * @see Data::getVersionPreview
     *
     * @param array|null $data
     * @param DataObject\Concrete|null $object
     * @param mixed $params
     *
     * @return string
     */
    public function getVersionPreview($data, $object = null, $params = [])
    {
        $items = [];
        if (is_array($data) && count($data) > 0) {
            foreach ($data as $metaObject) {
                $o = $metaObject->getObject();

                if (!$o) {
                    continue;
                }

                $item = $o->getRealFullPath();

                if (count($metaObject->getData())) {
                    $subItems = [];
                    foreach ($metaObject->getData() as $key => $value) {
                        if (!$value) {
                            continue;
                        }
                        $subItems[] = $key . ': ' . $value;
                    }

                    if (count($subItems)) {
                        $item .= ' <br/><span class="preview-metadata">[' . implode(' | ', $subItems) . ']</span>';
                    }
                }

                $items[] = $item;
            }

            return implode('<br />', $items);
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function checkValidity($data, $omitMandatoryCheck = false, $params = [])
    {
        if (!$omitMandatoryCheck && $this->getMandatory() && empty($data)) {
            throw new Element\ValidationException('Empty mandatory field [ ' . $this->getName() . ' ]');
        }

        if (is_array($data)) {
            $this->performMultipleAssignmentCheck($data);

            foreach ($data as $objectMetadata) {
                if (!($objectMetadata instanceof DataObject\Data\ObjectMetadata)) {
                    throw new Element\ValidationException('Expected DataObject\\Data\\ObjectMetadata');
                }

                $o = $objectMetadata->getObject();
                if ($o->getClassName() != $this->getAllowedClassId() || !($o instanceof DataObject\Concrete)) {
                    if ($o instanceof DataObject\Concrete) {
                        $id = $o->getId();
                    } else {
                        $id = '??';
                    }

                    throw new Element\ValidationException('Invalid object relation to object [' . $id . '] in field ' . $this->getName() . ' , tried to assign ' . $o->getId());
                }
            }

            if ($this->getMaxItems() && count($data) > $this->getMaxItems()) {
                throw new Element\ValidationException('Number of allowed relations in field `' . $this->getName() . '` exceeded (max. ' . $this->getMaxItems() . ')');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getForCsvExport($object, $params = [])
    {
        $data = $this->getDataFromObjectParam($object, $params);
        if (is_array($data)) {
            $paths = [];
            foreach ($data as $metaObject) {
                $eo = $metaObject->getObject();
                if ($eo instanceof Element\ElementInterface) {
                    $paths[] = $eo->getRealFullPath();
                }
            }

            return implode(',', $paths);
        }

        return '';
    }

    /**
     * @param DataObject\Data\ObjectMetadata[]|null $data
     *
     * @return array
     */
    public function resolveDependencies($data)
    {
        $dependencies = [];

        if (is_array($data) && count($data) > 0) {
            foreach ($data as $metaObject) {
                $o = $metaObject->getObject();
                if ($o instanceof DataObject\AbstractObject) {
                    $dependencies['object_' . $o->getId()] = [
                        'id' => $o->getId(),
                        'type' => 'object',
                    ];
                }
            }
        }

        return $dependencies;
    }

    /**
     * {@inheritdoc}
     */
    public function save($object, $params = [])
    {
        if (!DataObject::isDirtyDetectionDisabled() && $object instanceof Element\DirtyIndicatorInterface) {
            if ($object instanceof DataObject\Localizedfield) {
                if ($object->getObject() instanceof Element\DirtyIndicatorInterface) {
                    if (!$object->hasDirtyFields()) {
                        return;
                    }
                }
            } else {
                if ($this->supportsDirtyDetection()) {
                    if (!$object->isFieldDirty($this->getName())) {
                        return;
                    }
                }
            }
        }

        $objectsMetadata = $this->getDataFromObjectParam($object, $params);

        $classId = null;
        $objectId = null;

        if ($object instanceof DataObject\Concrete) {
            $objectId = $object->getId();
        } elseif ($object instanceof DataObject\Fieldcollection\Data\AbstractData) {
            $objectId = $object->getObject()->getId();
        } elseif ($object instanceof DataObject\Localizedfield) {
            $objectId = $object->getObject()->getId();
        } elseif ($object instanceof DataObject\Objectbrick\Data\AbstractData) {
            $objectId = $object->getObject()->getId();
        }

        if ($object instanceof DataObject\Localizedfield) {
            $classId = $object->getClass()->getId();
        } elseif ($object instanceof DataObject\Objectbrick\Data\AbstractData || $object instanceof DataObject\Fieldcollection\Data\AbstractData) {
            $classId = $object->getObject()->getClassId();
        } else {
            $classId = $object->getClassId();
        }

        $table = 'object_metadata_' . $classId;
        $db = Db::get();

        $this->enrichDataRow($object, $params, $classId, $relation);

        $position = (isset($relation['position']) && $relation['position']) ? $relation['position'] : '0';
        $context = $params['context'] ?? null;

        if (isset($context['containerType'], $context['subContainerType']) && ($context['containerType'] === 'fieldcollection' || $context['containerType'] === 'objectbrick') && $context['subContainerType'] === 'localizedfield') {
            $index = $context['index'] ?? null;
            $containerName = $context['fieldname'] ?? null;

            if ($context['containerType'] === 'fieldcollection') {
                $ownerName = '/' . $context['containerType'] . '~' . $containerName . '/' . $index . '/%';
            } else {
                $ownerName = '/' . $context['containerType'] . '~' . $containerName . '/%';
            }

            $sql = Db\Helper::quoteInto($db, 'o_id = ?', $objectId) . " AND ownertype = 'localizedfield' AND "
                . Db\Helper::quoteInto($db, 'ownername LIKE ?', $ownerName)
                . ' AND ' . Db\Helper::quoteInto($db, 'fieldname = ?', $this->getName())
                . ' AND ' . Db\Helper::quoteInto($db, 'position = ?', $position);
        } else {
            $sql = Db\Helper::quoteInto($db, 'o_id = ?', $objectId) . ' AND ' . Db\Helper::quoteInto($db, 'fieldname = ?', $this->getName())
                . ' AND ' . Db\Helper::quoteInto($db, 'position = ?', $position);

            if ($context) {
                if (!empty($context['fieldname'])) {
                    $sql .= ' AND '.Db\Helper::quoteInto($db, 'ownername = ?', $context['fieldname']);
                }

                if (!DataObject::isDirtyDetectionDisabled() && $object instanceof Element\DirtyIndicatorInterface) {
                    if ($context['containerType']) {
                        $sql .= ' AND '.Db\Helper::quoteInto($db, 'ownertype = ?', $context['containerType']);
                    }
                }
            }
        }

        $db->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $sql);

        if (!empty($objectsMetadata)) {
            if ($object instanceof DataObject\Localizedfield || $object instanceof DataObject\Objectbrick\Data\AbstractData
                || $object instanceof DataObject\Fieldcollection\Data\AbstractData) {
                $objectConcrete = $object->getObject();
            } else {
                $objectConcrete = $object;
            }

            $counter = 1;
            foreach ($objectsMetadata as $mkey => $meta) {
                $ownerName = isset($relation['ownername']) ? $relation['ownername'] : null;
                $ownerType = isset($relation['ownertype']) ? $relation['ownertype'] : null;
                $meta->save($objectConcrete, $ownerType, $ownerName, $position, $counter);

                $counter++;
            }
        }

        parent::save($object, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function preGetData(/** mixed */ $container, /** array */ $params = []) // : mixed
    {
        $data = null;
        if ($container instanceof DataObject\Concrete) {
            $data = $container->getObjectVar($this->getName());
            if (!$container->isLazyKeyLoaded($this->getName())) {
                $data = $this->load($container);

                $container->setObjectVar($this->getName(), $data);
                $this->markLazyloadedFieldAsLoaded($container);
            }
        } elseif ($container instanceof DataObject\Localizedfield) {
            $data = $params['data'];
        } elseif ($container instanceof DataObject\Fieldcollection\Data\AbstractData) {
            parent::loadLazyFieldcollectionField($container);
            $data = $container->getObjectVar($this->getName());
        } elseif ($container instanceof DataObject\Objectbrick\Data\AbstractData) {
            parent::loadLazyBrickField($container);
            $data = $container->getObjectVar($this->getName());
        }

        // note, in case of advanced many to many relations we don't want to force the loading of the element
        // instead, ask the database directly
        return Element\Service::filterUnpublishedAdvancedElements($data);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($object, $params = [])
    {
        $db = Db::get();
        $context = $params['context'] ?? null;

        if (isset($context['containerType'], $context['subContainerType']) && ($context['containerType'] === 'fieldcollection' || $context['containerType'] === 'objectbrick') && $context['subContainerType'] === 'localizedfield') {
            if ($context['containerType'] === 'objectbrick') {
                throw new \Exception('deletemeta not implemented');
            }
            $containerName = $context['fieldname'] ?? null;
            $index = $context['index'];
            $db->executeStatement(
                'DELETE FROM object_metadata_' . $object->getClassId()
                . ' WHERE ' . Db\Helper::quoteInto($db, 'o_id = ?', $object->getId()) . " AND ownertype = 'localizedfield' AND "
                . Db\Helper::quoteInto($db, 'ownername LIKE ?', '/' . $context['containerType'] . '~' . $containerName . '/' . "$index . /%")
                . ' AND ' . Db\Helper::quoteInto($db, 'fieldname = ?', $this->getName())
            );
        } else {
            $deleteConditions = [
                'o_id' => $object->getId(),
                'fieldname' => $this->getName(),
            ];
            if ($context) {
                if (!empty($context['fieldname'])) {
                    $deleteConditions['ownername'] = $context['fieldname'];
                }

                if (!DataObject::isDirtyDetectionDisabled() && $object instanceof Element\DirtyIndicatorInterface) {
                    if ($context['containerType']) {
                        $deleteConditions['ownertype'] = $context['containerType'];
                    }
                }
            }

            $db->delete('object_metadata_' . $object->getClassId(), $deleteConditions);
        }
    }

    /**
     * @param string|null $allowedClassId
     *
     * @return $this
     */
    public function setAllowedClassId($allowedClassId)
    {
        $this->allowedClassId = $allowedClassId;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getAllowedClassId()
    {
        return $this->allowedClassId;
    }

    /**
     * @param array|string|null $visibleFields
     *
     * @return $this
     */
    public function setVisibleFields($visibleFields)
    {
        /**
         * @extjs6
         */
        if (is_array($visibleFields)) {
            if (count($visibleFields)) {
                $visibleFields = implode(',', $visibleFields);
            } else {
                $visibleFields = null;
            }
        }
        $this->visibleFields = $visibleFields;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getVisibleFields()
    {
        return $this->visibleFields;
    }

    /**
     * @param array $columns
     *
     * @return $this
     */
    public function setColumns($columns)
    {
        if (isset($columns['key'])) {
            $columns = [$columns];
        }
        usort($columns, [$this, 'sort']);

        $this->columns = [];
        $this->columnKeys = [];
        foreach ($columns as $c) {
            $this->columns[] = $c;
            $this->columnKeys[] = $c['key'];
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * @return array
     */
    public function getColumnKeys()
    {
        $this->columnKeys = [];
        foreach ($this->columns as $c) {
            $this->columnKeys[] = $c['key'];
        }

        return $this->columnKeys;
    }

    /**
     * @return bool
     */
    public function getEnableBatchEdit()
    {
        return $this->enableBatchEdit;
    }

    /**
     * @param bool $enableBatchEdit
     */
    public function setEnableBatchEdit($enableBatchEdit)
    {
        $this->enableBatchEdit = $enableBatchEdit;
    }

    /**
     * @param DataObject\ClassDefinition $class
     * @param array $params
     */
    public function classSaved($class/**, $params = []**/)
    {
        /** @var DataObject\Data\ObjectMetadata $temp */
        $temp = \Pimcore::getContainer()->get('pimcore.model.factory')
            ->build('Pimcore\Model\DataObject\Data\ObjectMetadata', [
                'fieldname' => null,
            ]);

        $temp->getDao()->createOrUpdateTable($class);
    }

    /**
     * {@inheritdoc}
     */
    public function rewriteIds(/** mixed */ $container, /** array */ $idMapping, /** array */ $params = []) /** :mixed */
    {
        $data = $this->getDataFromObjectParam($container, $params);

        if (is_array($data)) {
            foreach ($data as &$metaObject) {
                $eo = $metaObject->getObject();
                if ($eo instanceof Element\ElementInterface) {
                    $id = $eo->getId();
                    $type = Element\Service::getElementType($eo);

                    if (array_key_exists($type, $idMapping) && array_key_exists($id, $idMapping[$type])) {
                        $newElement = Element\Service::getElementById($type, $idMapping[$type][$id]);
                        $metaObject->setObject($newElement);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function synchronizeWithMainDefinition(DataObject\ClassDefinition\Data $mainDefinition)
    {
        if ($mainDefinition instanceof self) {
            $this->allowedClassId = $mainDefinition->getAllowedClassId();
            $this->visibleFields = $mainDefinition->getVisibleFields();
            $this->columns = $mainDefinition->getColumns();
        }
    }

    /**
     * @deprecated will be removed in Pimcore 11
     * {@inheritdoc}
     */
    public function synchronizeWithMasterDefinition(DataObject\ClassDefinition\Data $masterDefinition)
    {
        trigger_deprecation(
            'pimcore/pimcore',
            '10.6.0',
            sprintf('%s is deprecated and will be removed in Pimcore 11. Use %s instead.', __METHOD__, str_replace('Master', 'Main', __METHOD__))
        );

        $this->synchronizeWithMainDefinition($masterDefinition);
    }

    /**
     * {@inheritdoc}
     */
    public function enrichLayoutDefinition(/* ?Concrete */ $object, /* array */ $context = []) // : static
    {
        $classId = $this->allowedClassId;

        if (!$classId) {
            return $this;
        }

        if (is_numeric($classId)) {
            $class = DataObject\ClassDefinition::getById($classId);
        } else {
            $class = DataObject\ClassDefinition::getByName($classId);
        }

        if (!$class) {
            return $this;
        }

        if (!$this->visibleFields) {
            return $this;
        }

        $this->visibleFieldDefinitions = [];

        $translator = \Pimcore::getContainer()->get('translator');

        $visibleFields = explode(',', $this->visibleFields);
        foreach ($visibleFields as $field) {
            $fd = $class->getFieldDefinition($field, $context);

            if (!$fd) {
                $fieldFound = false;
                /** @var Localizedfields|null $localizedfields */
                $localizedfields = $class->getFieldDefinitions($context)['localizedfields'] ?? null;
                if ($localizedfields) {
                    if ($fd = $localizedfields->getFieldDefinition($field)) {
                        $this->visibleFieldDefinitions[$field]['name'] = $fd->getName();
                        $this->visibleFieldDefinitions[$field]['title'] = $fd->getTitle();
                        $this->visibleFieldDefinitions[$field]['fieldtype'] = $fd->getFieldType();

                        if ($fd instanceof DataObject\ClassDefinition\Data\Select || $fd instanceof DataObject\ClassDefinition\Data\Multiselect) {
                            $this->visibleFieldDefinitions[$field]['options'] = $fd->getOptions();
                        }

                        $fieldFound = true;
                    }
                }

                if (!$fieldFound) {
                    $this->visibleFieldDefinitions[$field]['name'] = $field;
                    $this->visibleFieldDefinitions[$field]['title'] = $translator->trans($field, [], 'admin');
                    $this->visibleFieldDefinitions[$field]['fieldtype'] = 'input';
                }
            } else {
                $this->visibleFieldDefinitions[$field]['name'] = $fd->getName();
                $this->visibleFieldDefinitions[$field]['title'] = $fd->getTitle();
                $this->visibleFieldDefinitions[$field]['fieldtype'] = $fd->getFieldType();
                $this->visibleFieldDefinitions[$field]['noteditable'] = true;

                if (
                    $fd instanceof DataObject\ClassDefinition\Data\Select
                    || $fd instanceof DataObject\ClassDefinition\Data\Multiselect
                    || $fd instanceof DataObject\ClassDefinition\Data\BooleanSelect
                ) {
                    if (
                        $fd instanceof DataObject\ClassDefinition\Data\Select
                        || $fd instanceof DataObject\ClassDefinition\Data\Multiselect
                    ) {
                        $this->visibleFieldDefinitions[$field]['optionsProviderClass'] = $fd->getOptionsProviderClass();
                    }

                    $this->visibleFieldDefinitions[$field]['options'] = $fd->getOptions();
                }
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($value, $params = [])
    {
        if (is_array($value)) {
            $object = $params['object'] ?? null;
            $result = [];
            foreach ($value as $elementMetadata) {
                $elementData = $elementMetadata['element'];

                $type = $elementData['type'];
                $id = $elementData['id'];
                $target = Element\Service::getElementById($type, $id);
                if ($target instanceof DataObject\Concrete) {
                    $columns = $elementMetadata['columns'];
                    $fieldname = $elementMetadata['fieldname'];
                    $data = $elementMetadata['data'];

                    $item = new DataObject\Data\ObjectMetadata($fieldname, $columns, $target);
                    $item->_setOwner($object);
                    $item->_setOwnerFieldname($this->getName());
                    $item->setData($data);
                    $result[] = $item;
                }
            }

            return $result;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($value, $params = [])
    {
        if (is_array($value)) {
            $result = [];
            /** @var DataObject\Data\ObjectMetadata $elementMetadata */
            foreach ($value as $elementMetadata) {
                $element = $elementMetadata->getElement();

                $type = Element\Service::getElementType($element);
                $id = $element->getId();
                $result[] = [
                    'element' => [
                        'type' => $type,
                        'id' => $id,
                    ],
                    'fieldname' => $elementMetadata->getFieldname(),
                    'columns' => $elementMetadata->getColumns(),
                    'data' => $elementMetadata->getData(), ];
            }

            return $result;
        }

        return null;
    }

    /**
     * @internal
     *
     * @param mixed $originalData
     * @param mixed $data
     * @param Concrete $object
     * @param array $params
     *
     * @return array
     */
    protected function processDiffDataForEditMode($originalData, $data, $object = null, $params = [])
    {
        if ($data) {
            $data = $data[0];

            $items = $data['data'];
            $newItems = [];
            if ($items) {
                $columns = array_merge(['id', 'fullpath'], $this->getColumnKeys());
                foreach ($items as $itemBeforeCleanup) {
                    $unique = $this->buildUniqueKeyForDiffEditor($itemBeforeCleanup);
                    $item = [];

                    foreach ($itemBeforeCleanup as $key => $value) {
                        if (in_array($key, $columns)) {
                            $item[$key] = $value;
                        }
                    }

                    $itemId = json_encode($item);
                    $raw = $itemId;

                    $newItems[] = [
                        'itemId' => $itemId,
                        'title' => $item['fullpath'],
                        'raw' => $raw,
                        'gridrow' => $item,
                        'unique' => $unique,
                    ];
                }
                $data['data'] = $newItems;
            }

            $data['value'] = [
                'type' => 'grid',
                'columnConfig' => [
                    'id' => [
                        'width' => 60,
                    ],
                    'fullpath' => [
                        'flex' => 2,
                    ],

                ],
                'html' => $this->getVersionPreview($originalData, $object, $params),
            ];

            $newData = [];
            $newData[] = $data;

            return $newData;
        }

        return $data;
    }

    /**
     * @return bool
     */
    public function getAllowMultipleAssignments()
    {
        return $this->allowMultipleAssignments;
    }

    /**
     * @param bool $allowMultipleAssignments
     *
     * @return $this
     */
    public function setAllowMultipleAssignments($allowMultipleAssignments)
    {
        $this->allowMultipleAssignments = $allowMultipleAssignments;

        return $this;
    }

    /**
     * @internal
     *
     * @param DataObject\Data\ObjectMetadata $item
     *
     * @return string
     */
    protected function buildUniqueKeyForAppending($item)
    {
        $element = $item->getElement();
        $elementType = Element\Service::getElementType($element);
        $id = $element->getId();

        return $elementType . $id;
    }

    /**
     * {@inheritdoc}
     */
    public function getPhpdocInputType(): ?string
    {
        return '\\'.DataObject\Data\ObjectMetadata::class.'[]';
    }

    /**
     * {@inheritdoc}
     */
    public function getPhpdocReturnType(): ?string
    {
        return '\\'.DataObject\Data\ObjectMetadata::class.'[]';
    }
}
