<?php
/**
 * Webiny Framework (http://www.webiny.com/framework)
 *
 * @copyright Copyright Webiny LTD
 */

namespace Webiny\Component\Entity;

use Webiny\Component\Entity\Attribute\AttributeAbstract;
use Webiny\Component\Entity\Attribute\AttributeType;
use Webiny\Component\Entity\Attribute\CharAttribute;
use Webiny\Component\Entity\Attribute\Exception\ValidationException;
use Webiny\Component\Entity\Attribute\Many2ManyAttribute;
use Webiny\Component\Entity\Attribute\One2ManyAttribute;
use Webiny\Component\Entity\AttributeStorage\Many2ManyStorage;
use Webiny\Component\Mongo\MongoResult;
use Webiny\Component\StdLib\FactoryLoaderTrait;
use Webiny\Component\StdLib\StdLibTrait;
use Webiny\Component\StdLib\StdObject\ArrayObject\ArrayObject;
use Webiny\Component\StdLib\StdObject\StdObjectWrapper;

/**
 * Entity
 * @package \Webiny\Component\Entity
 */
abstract class EntityAbstract implements \ArrayAccess
{
    use StdLibTrait, EntityTrait, FactoryLoaderTrait;

    /**
     * This array serves as a log to prevent infinite save loop
     * @var array
     */
    private static $saved = [];

    /**
     * Entity attributes
     * @var ArrayObject
     */
    protected $attributes;

    /**
     * @var string Entity collection name
     */
    protected static $entityCollection = null;

    /**
     * View mask (used for grids and many2one input fields)
     * @var string
     */
    protected static $entityMask = '{id}';

    /**
     * This method is called during instantiation to build entity structure
     * @return void
     */
    protected abstract function entityStructure();

    /**
     * Get collection name
     * @return string
     */
    public static function getEntityCollection()
    {
        return static::$entityCollection;
    }

    /**
     * Find entity by ID
     *
     * @param $id
     *
     * @return null|EntityAbstract
     */
    public static function findById($id)
    {
        if (!$id || strlen($id) != 24) {
            return null;
        }
        $instance = static::entity()->get(get_called_class(), $id);
        if ($instance) {
            return $instance;
        }
        $data = static::entity()->getDatabase()->findOne(static::$entityCollection, ['_id' => new \MongoId($id)]);
        if (!$data) {
            return null;
        }
        $instance = new static;
        $data['__webiny_db__'] = true;
        $instance->populate($data);

        return static::entity()->add($instance);
    }

    /**
     * Count records using given criteria
     *
     * @param array $conditions
     *
     * @return int
     *
     */
    public static function count(array $conditions = [])
    {
        return static::entity()->getDatabase()->count(static::$entityCollection, $conditions);
    }

    /**
     * Find entity by array of conditions
     *
     * @param array $conditions
     *
     * @return null|EntityAbstract
     * @throws EntityException
     */
    public static function findOne(array $conditions = [])
    {
        $data = static::entity()->getDatabase()->findOne(static::$entityCollection, $conditions);
        if (!$data) {
            return null;
        }
        $instance = new static;
        $data['__webiny_db__'] = true;
        $instance->populate($data);

        return static::entity()->add($instance);
    }

    /**
     * Find entities
     *
     * @param mixed $conditions
     *
     * @param array $order Example: ['-name', '+title']
     * @param int   $limit
     * @param int   $page
     *
     * @return EntityCollection
     */
    public static function find(array $conditions = [], array $order = [], $limit = 0, $page = 0)
    {
        /**
         * Convert order parameters to Mongo format
         */
        $order = self::parseOrderParameters($order);
        $offset = $limit * ($page > 0 ? $page - 1 : 0);

        return new EntityCollection(get_called_class(), static::$entityCollection, $conditions, $order, $limit, $offset);
    }

    /**
     * Entity constructor
     */
    public function __construct()
    {
        $this->attributes = $this->arr();
        /**
         * Add ID to the list of attributes
         */
        $this->attr('id')->char();

        $this->entityStructure();
    }

    /**
     * @param $attribute
     *
     * @return EntityAttributeBuilder
     */
    public function attr($attribute)
    {
        return EntityAttributeBuilder::getInstance()->setContext($this->attributes, $attribute)->setEntity($this);
    }

    /**
     * Convert EntityAbstract to array with specified fields.
     * If no fields are specified, array will contain all simple and Many2One attributes
     *
     * @param string $fields List of fields to extract
     *
     * @param int    $nestedLevel How many levels to extract (Default: 1, means SELF + 1 level)
     *
     * @return array
     */
    public function toArray($fields = '', $nestedLevel = 1)
    {
        $dataExtractor = new EntityDataExtractor($this, $nestedLevel);

        return $dataExtractor->extractData($fields);
    }


    /**
     * Return string representation of entity
     * @return mixed
     */
    public function __toString()
    {
        return $this->getMaskedValue();
    }

    /**
     * Is this entity already saved?
     *
     * @return bool
     */
    public function exists()
    {
        return $this->id !== null;
    }

    /**
     * Get entity attribute
     *
     * @param string $attribute
     *
     * @throws EntityException
     * @return AttributeAbstract
     */
    public function getAttribute($attribute)
    {
        if (!$this->attributes->keyExists($attribute)) {
            throw new EntityException(EntityException::ATTRIBUTE_NOT_FOUND, [
                $attribute,
                get_class($this)
            ]);
        }

        return $this->attributes[$attribute];
    }

    /**
     * Get all entity attributes
     *
     * @return ArrayObject
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Get entity ID
     * @return CharAttribute
     */
    public function getId()
    {
        return $this->attributes['id'];
    }

    public function getMaskedValue()
    {
        $maskItems = [];
        preg_match_all('/\{(.*?)\}/', static::$entityMask, $maskItems);
        $maskedValue = $this->str(static::$entityMask);
        foreach ($maskItems[1] as $attr) {
            $maskedValue->replace('{' . $attr . '}', $this->getAttribute($attr)->getValue());
        }

        return $maskedValue->val();
    }

    /**
     * Save entity attributes to database
     */
    public function save()
    {
        $data = [];
        foreach ($this->getAttributes() as $key => $attr) {
            if (!$this->isInstanceOf($attr, AttributeType::ONE2MANY) && !$this->isInstanceOf($attr, AttributeType::MANY2MANY)) {
                if ($attr->getStoreToDb()) {
                    $data[$key] = $attr->getDbValue();
                }
            }
        }

        /**
         * Insert or update
         */
        if (!$this->exists()) {
            $data['_id'] = new \MongoId();
            $data['id'] = (string)$data['_id'];
            $this->entity()->getDatabase()->insert(static::$entityCollection, $data);
            $this->id = $data['id'];
        } else {
            // Check if this entity was already saved during save cycle through other relational attributes
            if (array_key_exists($this->id, self::$saved)) {
                return;
            }
            $where = ['_id' => new \MongoId($this->id)];
            $this->entity()->getDatabase()->update(static::$entityCollection, $where, ['$set' => $data], ['upsert' => true]);
        }

        // Store this entity's id to prevent infinite saving loop
        self::$saved[$this->id] = true;

        /**
         * Now save One2Many values
         */
        foreach ($this->getAttributes() as $attr) {
            /* @var $attr One2ManyAttribute */
            if ($this->isInstanceOf($attr, AttributeType::ONE2MANY)) {
                foreach ($attr->getValue() as $item) {
                    $item->getAttribute($attr->getRelatedAttribute())->setValue($this);
                    $item->save();
                }
                /**
                 * The value of one2many attribute must be set to null to trigger data reload on next access.
                 * This is necessary when we have circular references, and parent record does not get it's many2one ID saved
                 * until all child referenced objects are saved. Only then can we get proper links between referenced classes.
                 */
                $attr->setValue(null);
            }
        }

        /**
         * Now save Many2Many values
         */
        foreach ($this->getAttributes() as $attr) {
            /* @var $attr Many2ManyAttribute */
            if ($this->isInstanceOf($attr, AttributeType::MANY2MANY)) {
                Many2ManyStorage::getInstance()->save($attr);
            }
        }

        // Now that this entity is saved, remove its id from save log
        unset(self::$saved[$this->id]);

        return true;
    }

    /**
     * Delete entity
     * @return bool
     * @return bool
     * @throws EntityException
     */
    public function delete()
    {
        /**
         * Check for many2many attributes and make sure related Entity has a corresponding many2many attribute defined.
         * If not - deleting is not allowed.
         */

        /* @var $attr Many2ManyAttribute */
        $thisClass = '\\' . get_class($this);
        foreach ($this->getAttributes() as $attrName => $attr) {
            if ($this->isInstanceOf($attr, AttributeType::MANY2MANY)) {
                $foundMatch = false;
                $relatedClass = $attr->getEntity();
                $relatedEntity = new $relatedClass;
                /* @var $relAttr Many2ManyAttribute */
                foreach ($relatedEntity->getAttributes() as $relAttr) {
                    if ($this->isInstanceOf($relAttr, AttributeType::MANY2MANY) && $relAttr->getEntity() == $thisClass) {
                        $foundMatch = true;
                    }
                }

                if (!$foundMatch) {
                    throw new EntityException(EntityException::NO_MATCHING_MANY2MANY_ATTRIBUTE_FOUND, [
                        $thisClass,
                        $relatedClass,
                        $attrName
                    ]);
                }
            }
        }

        /**
         * First check all one2many records to see if deletion is restricted
         */
        $deleteAttributes = [];
        foreach ($this->getAttributes() as $key => $attr) {
            if ($this->isInstanceOf($attr, AttributeType::ONE2MANY)) {
                /* @var $attr One2ManyAttribute */
                if ($attr->getOnDelete() == 'restrict' && $this->getAttribute($key)->getValue()->count() > 0) {
                    throw new EntityException(EntityException::ENTITY_DELETION_RESTRICTED, [$key]);
                }
                $deleteAttributes[] = $key;
            }
        }

        /**
         * Delete many2many records
         */
        foreach ($this->getAttributes() as $attr) {
            /* @var $attr Many2ManyAttribute */
            if ($this->isInstanceOf($attr, AttributeType::MANY2MANY)) {
                $firstClassName = $this->extractClassName($attr->getParentEntity());
                $query = [$firstClassName => $this->id];
                $this->entity()->getDatabase()->remove($attr->getIntermediateCollection(), $query);
            }
        }

        /**
         * Delete one2many records
         */
        foreach ($deleteAttributes as $attr) {
            foreach ($this->getAttribute($attr)->getValue() as $item) {
                $item->delete();
            }
        }

        /**
         * Delete $this
         */
        $this->entity()->getDatabase()->remove(static::$entityCollection, ['_id' => $this->entity()->getDatabase()->id($this->id)]);

        static::entity()->remove($this);

        return true;
    }

    /**
     * Populate entity with given data
     *
     * @param array $data
     *
     * @throws EntityException
     * @return $this
     */
    public function populate($data)
    {
        if (is_null($data)) {
            return $this;
        }

        $data = $this->normalizeData($data);

        $fromDb = false;
        if ($this->isDbData($data)) {
            $fromDb = true;
        } else {
            unset($data['id']);
            unset($data['_id']);
        }

        $entityCollectionClass = '\Webiny\Component\Entity\EntityCollection';
        $validation = $this->arr();
        /* @var $entityAttribute AttributeAbstract */
        foreach ($this->attributes as $attributeName => $entityAttribute) {

            // Skip population of protected attributes if data is not coming from DB
            if(!$fromDb && $entityAttribute->getSkipOnPopulate()){
                continue;
            }

            // Dynamic attributes from database should be populated without any checks, and skipped otherwise
            if ($this->isInstanceOf($entityAttribute, AttributeType::DYNAMIC)) {
                if ($fromDb && isset($data[$attributeName])) {
                    $entityAttribute->setValue($data[$attributeName], $fromDb);
                }
                continue;
            }

            /**
             * Check if attribute is required and it's value is set or maybe value was already assigned
             */
            $hasValue = !is_null($entityAttribute->getValue());
            if ($entityAttribute->isRequired() && !isset($data[$attributeName]) && !$this->exists() && !$hasValue) {
                $ex = new ValidationException(ValidationException::VALIDATION_FAILED);
                $ex->addError($attributeName, ValidationException::REQUIRED, []);
                $validation[$attributeName] = $ex;
                continue;
            }

            /**
             * In case it is an update - if the attribute is not in new $data, it's no big deal, we already have the previous value.
             */
            $dataIsSet = array_key_exists($attributeName, $data);
            if (!$dataIsSet && $this->exists()) {
                continue;
            }

            $canPopulate = !$this->exists() || $fromDb || !$entityAttribute->getOnce();
            if ($dataIsSet && $canPopulate) {
                $dataValue = $data[$attributeName];
                $isOne2Many = $this->isInstanceOf($entityAttribute, AttributeType::ONE2MANY);
                $isMany2Many = $this->isInstanceOf($entityAttribute, AttributeType::MANY2MANY);
                $isMany2One = $this->isInstanceOf($entityAttribute, AttributeType::MANY2ONE);

                if ($isMany2One) {
                    try {
                        // If simple ID or null - set and forget
                        if (is_string($dataValue) || is_null($dataValue)) {
                            $entityAttribute->setValue($dataValue);
                            continue;
                        }

                        $entityAttribute->setValue($dataValue);
                    } catch (ValidationException $e) {
                        $validation[$attributeName] = $e;
                        continue;
                    }
                } elseif ($isOne2Many) {
                    $entityClass = $entityAttribute->getEntity();

                    // Validate One2Many attribute value
                    if (!$this->isArray($dataValue) && !$this->isArrayObject($dataValue) && !$this->isInstanceOf($dataValue,
                            $entityCollectionClass)
                    ) {
                        $ex = new ValidationException(ValidationException::VALIDATION_FAILED);
                        $ex->addError($attributeName, ValidationException::DATA_TYPE, [
                            'array, ArrayObject or EntityCollection',
                            gettype($dataValue)
                        ]);
                        $validation[$attributeName] = $ex;
                        continue;
                    }
                    /* @var $entityAttribute One2ManyAttribute */
                    foreach ($dataValue as $item) {
                        $itemEntity = false;

                        // $item can be an array of data, EntityAbstract or a simple MongoId string
                        if ($this->isInstanceOf($item, '\Webiny\Component\Entity\EntityAbstract')) {
                            $itemEntity = $item;
                        } elseif ($this->isArray($item) || $this->isArrayObject($item)) {
                            $itemEntity = $entityClass::findById(isset($item['id']) ? $item['id'] : false);
                        } elseif ($this->isString($item) && $this->entity()->getDatabase()->isMongoId($item)) {
                            $itemEntity = $entityClass::findById($item);
                        }

                        // If instance was not found, create a new entity instance
                        if (!$itemEntity) {
                            $itemEntity = new $entityClass;
                        }

                        // If $item is an array - use it to populate the entity instance
                        if ($this->isArray($item) || $this->isArrayObject($item)) {
                            $itemEntity->populate($item);
                        }

                        // Add One2Many entity instance to current entity's attribute
                        $entityAttribute->add($itemEntity);
                    }
                } elseif ($isMany2Many) {
                    $entityAttribute->add($dataValue);
                } else {
                    try {
                        $entityAttribute->setValue($dataValue, $fromDb);
                    } catch (ValidationException $e) {
                        $validation[$attributeName] = $e;
                    }
                }
            }
        }

        if ($validation->count() > 0) {
            $attributes = [];
            foreach ($validation as $attr => $error) {
                foreach ($error as $key => $value) {
                    $attributes[$key] = $value;
                }
            }
            $ex = new EntityException(EntityException::VALIDATION_FAILED, [$validation->count()]);
            $ex->setInvalidAttributes($attributes);
            throw $ex;
        }

        return $this;
    }

    /**
     * This method allows us to use simplified accessor methods.
     * Ex: $person->company->name
     *
     * @param $name
     *
     * @return AttributeAbstract
     */
    public function __get($name)
    {
        return $this->getAttribute($name)->getValue();
    }

    /**
     * This method allows setting attribute values through simple assignment
     * Ex: $person->name = 'Webiny';
     *
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        $this->getAttribute($name)->setValue($value);
    }


    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     *
     * @param mixed $offset <p>
     *                      An offset to check for.
     *                      </p>
     *
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     */
    public function offsetExists($offset)
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     *
     * @param mixed $offset <p>
     *                      The offset to retrieve.
     *                      </p>
     *
     * @return mixed Can return all value types.
     */
    public function offsetGet($offset)
    {
        return $this->__get($offset);
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     *
     * @param mixed $offset <p>
     *                      The offset to assign the value to.
     *                      </p>
     * @param mixed $value <p>
     *                      The value to set.
     *                      </p>
     *
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->__set($offset, $value);
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     *
     * @param mixed $offset <p>
     *                      The offset to unset.
     *                      </p>
     *
     * @return void
     */
    public function offsetUnset($offset)
    {
        // Nothing to unset
    }

    /**
     * Used for checking if the entity populate data is coming from database
     *
     * @param $data
     *
     * @return bool
     */
    protected function isDbData($data)
    {
        return isset($data['__webiny_db__']) && $data['__webiny_db__'];
    }

    /**
     * Parse order parameters and construct parameters suitable for MongoDB
     *
     * @param $order
     *
     * @return array
     */
    private static function parseOrderParameters($order)
    {
        $parsedOrder = [];
        if (count($order) > 0) {
            foreach ($order as $key => $o) {
                // Check if $order array is already formatted properly
                if (!is_numeric($key) && is_numeric($o)) {
                    $parsedOrder[$key] = $o;
                    continue;
                }
                $o = self::str($o);
                if ($o->startsWith('-')) {
                    $parsedOrder[$o->subString(1, 0)->val()] = -1;
                } elseif ($o->startsWith('+')) {
                    $parsedOrder[$o->subString(1, 0)->val()] = 1;
                } else {
                    $parsedOrder[$o->val()] = 1;
                }
            }
        }

        return $parsedOrder;
    }

    /**
     * Extract short class name from class namespace or class instance
     *
     * @param string|EntityAbstract $class
     *
     * @return string
     */
    private function extractClassName($class)
    {
        if (!$this->isString($class)) {
            $class = get_class($class);
        }

        return $this->str($class)->explode('\\')->last()->val();
    }

    private function normalizeData($data)
    {
        if ($data instanceof MongoResult) {
            return $data->toArray();
        }

        if ($this->isArray($data) || $this->isArrayObject($data)) {
            return StdObjectWrapper::toArray($data);
        }


        return $data;
    }
}