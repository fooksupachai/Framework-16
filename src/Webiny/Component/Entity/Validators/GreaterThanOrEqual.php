<?php
namespace Webiny\Component\Entity\Validators;

use Webiny\Component\Entity\Attribute\AttributeAbstract;
use Webiny\Component\Entity\EntityValidatorInterface;
use Webiny\Component\Validation\ValidationTrait;

class GreaterThanOrEqual implements EntityValidatorInterface
{
    use ValidationTrait;

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return 'gte';
    }

    /**
     * @inheritDoc
     */
    public function validate(AttributeAbstract $attribute, $data, $params = [])
    {
        return $this->validation()->validate($data, 'gte:' . $params[0]);
    }
}