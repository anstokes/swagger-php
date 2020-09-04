<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations\MediaType;

/**
 * Use the operation context to extract useful information and inject that into the annotation.
 */
class AugmentMediaTypes
{
	
    public function __invoke(Analysis $analysis)
    {
        $allMediaTypes = $analysis->getAnnotationsOfType(MediaType::class);

        foreach ($allMediaTypes as $mediaType) {
			// Check if XML, with example(s) and schema
			if (($mediaType->mediaType == "application/xml") && (isset($mediaType->examples) && is_array($mediaType->examples)) && (isset($mediaType->schema) && $mediaType->schema)) {
				
				// Loop through example(s)
				foreach($mediaType->examples as $name => $exampleProperties) {
					if (isset($exampleProperties['$ref']) && $exampleProperties['$ref']) {
						// Find schema and example from references
						$schema = ($this->findReference($mediaType->schema->ref, $analysis, array('schema' => false)));
						$example = $this->findReference($exampleProperties['$ref'], $analysis, array('example' => false));

						// Build XML example
						$parentNode = (isset($schema->xml->name) ? $schema->xml->name : $schema->name);
						$xml = new \SimpleXMLElement('<' . $parentNode . '/>');
						$exampleValue = (isset($example->value) && is_array($example->value) ? array_filter($example->value): array());
						$this->arrayToXml($exampleValue, $xml, $schema);
						$exampleXml = $this->prettyXml($xml);
						//var_dump($exampleXml);

						// Summary
						$summary = (isset($example->summary) ? $example->summary : $name);
						$mediaType->examples[$name] = array('summary' => $summary, 'value' => $exampleXml);
					}
				}
			}
        }
    }
	
	protected function findReference($reference, $analysis, $findReturn)
	{
		$referenceParts = explode('/', $reference);
		
		// First part is #
		if (array_shift($referenceParts) == '#') {
			// Last part is name
			$name = array_pop($referenceParts);
			
			// Loop through parts
			$matched = true;
			$openapi = $analysis->openapi;
			foreach ($referenceParts as $referencePart) {
				if (isset($openapi->{$referencePart}) && $openapi->{$referencePart}) {
					$openapi = $openapi->{$referencePart};
				} else {
					$matched = false;
					break;
				}
			}
			
			// Check all elements matched
			if ($matched == true) {
				$matchNode = key($findReturn);
				$returnNode = reset($findReturn);
				
				// Loop through items
				foreach($openapi as $item) {
					if (isset($item->{$matchNode}) && ($item->{$matchNode} == $name)) {
						if ($returnNode) {
							return (isset($item->{$returnNode}) ? $item->{$returnNode} : false);
						} else {
							return $item;
						}
					}
				}
			}
		}
		
		// Not a valid reference
		return false;
	}
	
	
	function arrayToXml($data, &$xml_data, $schema = false, $wrapped = false)
	{
		// Loop through data
		foreach ($data as $key => $value ) {
			// Check if numeric
			if (is_numeric($key)) {
				// If wrapped then use wrapper name, else use 'item{n}'
				$key = ($wrapped ? $wrapped : 'item'.$key);
			}
			
			// Check if array
			if (is_array($value)) {
				// Wrap for array
				$isWrapped = ((substr($key, -1) == 's') ? substr($key, 0, -1) : $key);
				
				// Check if property exists for this field
				if ($property = $this->findSchemaProperty($schema, $key)) {
					$propertyName = (isset($property->xml->name) ? $property->xml->name : $property->property);
					if (substr($propertyName, -1) == 's') {
						$singular = substr($propertyName, 0, -1);	// Singular name for property (used when wrapping)
					} else {
						$singular = $propertyName;
					}
					$isWrapped = (isset($property->xml->wrapped) && $property->xml->wrapped ? $singular : false);
					
					// Filter multiple nodes
					if ($isWrapped) {
						$newValue = array();
						foreach($value as $index => $subArray) {
							if (is_array($subArray) && (key($subArray) == $singular) && (count($subArray) == 1)) {
								$newValue[] = $subArray[$singular];
							} else {
								$newValue[$index] = $subArray;
							}
						}
						$value = $newValue;
					}
					
					// Add child with name from schema
					$subnode = $xml_data->addChild($propertyName);
				} else {
					// Add child using key
					$subnode = $xml_data->addChild($key);
				}
				// Loop through array
				$this->arrayToXml($value, $subnode, $schema, $isWrapped);
			} else {
				// Add to xml
				$xml_data->addChild($key, htmlspecialchars((string)$value));
			}
		}
	}
	
	
	function prettyXml($xml)
	{
		$dom = new \DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($xml->asXML());
		return $dom->saveXML();
	}
	
	
	function findSchemaProperty($schema, $property)
	{
		if (isset($schema->properties)) {
			foreach($schema->properties as $schemaProperty) {
				if ($schemaProperty->property == $property) {
					return $schemaProperty;
				}
			}
		}
		return false;
	}
	
}
