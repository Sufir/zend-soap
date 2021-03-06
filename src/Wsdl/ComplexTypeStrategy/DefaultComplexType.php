<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Soap\Wsdl\ComplexTypeStrategy;

use ReflectionClass;
use Zend\Soap\Exception;
use Zend\Soap\Wsdl;

class DefaultComplexType extends AbstractComplexTypeStrategy
{
    /**
     * Add a complex type by recursively using all the class properties fetched via Reflection.
     *
     * @param  string $type Name of the class to be specified
     * @return string XSD Type for the given PHP type
     * @throws Exception\InvalidArgumentException if class does not exist
     */
    public function addComplexType($type)
    {
        if (!class_exists($type)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Cannot add a complex type %s that is not an object or where '
                . 'class could not be found in "DefaultComplexType" strategy.',
                $type
            ));
        }

        $class   = new ReflectionClass($type);
        $phpType = $class->getName();

        if (($soapType = $this->scanRegisteredTypes($phpType)) !== null) {
            return $soapType;
        }

        $dom = $this->getContext()->toDomDocument();
        $soapTypeName = $this->getContext()->translateType($phpType);
        $soapType     = Wsdl::TYPES_NS . ':' . $soapTypeName;

        // Register type here to avoid recursion
        $this->getContext()->addType($phpType, $soapType);

        $defaultProperties = $class->getDefaultProperties();
        $properties = $class->getProperties();

        $complexType = $dom->createElementNS(Wsdl::XSD_NS_URI, 'complexType');
        $complexType->setAttribute('name', $soapTypeName);

        $all = $dom->createElementNS(Wsdl::XSD_NS_URI, 'all');

        foreach ($properties as $property) {
            if ($property->isPublic() && preg_match_all('/@var\s+([^\s]+)/m', $property->getDocComment(), $matches)) {
                /**
                 * @todo check if 'xsd:element' must be used here (it may not be
                 * compatible with using 'complexType' node for describing other
                 * classes used as attribute types for current class
                 */
                $element = $dom->createElementNS(Wsdl::XSD_NS_URI, 'element');
                $element->setAttribute('name', $propertyName = $property->getName());
                $element->setAttribute('type', $this->getContext()->getType(trim($matches[1][0])));

                // If the default value is null, then this property is nillable.
                if ($defaultProperties[$propertyName] === null) {
                    $element->setAttribute('nillable', 'true');
                }

                $all->appendChild($element);
            }
        }

        $parent = $class->getParentClass();
        if ($parent && !$parent->getProperties()) {
            $extension = $dom->createElementNS(Wsdl::XSD_NS_URI, 'extension');
            $complexContent = $dom->createElementNS(Wsdl::XSD_NS_URI, 'complexContent');
            $extension->setAttribute('base', $this->addComplexType($parent->getName()));
            $extension->appendChild($all);
            $complexContent->appendChild($extension);
            $complexType->appendChild($complexContent);
        } else {
            $complexType->appendChild($all);
        }

        if ($class->isAbstract() && empty($properties)) {
            $complexType->setAttribute('abstract', 'true');
        }

        $this->getContext()->getSchema()->appendChild($complexType);

        return $soapType;
    }
}
