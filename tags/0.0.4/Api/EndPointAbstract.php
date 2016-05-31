<?php
namespace TrunkSoftware\Component\Rest\Api;
use TrunkSoftware\Component\Errors\Error;
use TrunkSoftware\Component\Http\Response;

use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;

use TrunkSoftware\Component\Http\Status;
use TrunkSoftware\Component\Serializer\Normalizer\PropelNormalizer;
use TrunkSoftware\Component\Serializer\Normalizer\DateTimeNormalizer;
use TrunkSoftware\Component\Serializer\Mapping\Loader\PhpLoader;
use TrunkSoftware\Component\Serializer\NameConverter\PropelNameConverter;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\Serializer;
#use TrunkSoftware\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;

use Symfony\Component\EventDispatcher\Tests\Service;

use Trunk\Wibbler\WibblerDependencyContainer;


abstract class EndPointAbstract implements EndPointInterface, EndPointNameInterface, OptionsInterface
{
	const API_GROUP = 'api';
	const MSG_MISSING_DATA = 'Missing data';
	const MSG_AUTH_FAILED = "Authentication failed";

	protected $request = null;
	protected $response = null;
	protected $user = null;
	protected $agency = null;
	protected $object = null;
	protected $debug = false;
	protected $debug_messages = array();
	/*protected $error = false;*/

	public static function create()
	{
		// @Reference http://stackoverflow.com/questions/2396415/what-does-new-self-mean-in-php
		// Instantiates child class instead of the class that static method is defined in
		return new static();
	}

	public function __construct() {
		$this->response = new Response();
	}

	public function setRequest( $request ) {
		$this->request = $request;
		return $this;
	}

	public function getRequest() {
		return $this->request;
	}

	public function setOptions( array $options ) {
		if( isset($options['current_user']) ) {
			$this->user = $options['current_user'];
		}
		if( isset($options['current_agency']) ) {
			$this->agency = $options['current_agency'];
		}
		return $this;
	}

	public function getResponseObject() {
		// Return actual response object
		if( $this->response === null ) {
			$this->response = new Response();
		}

		return $this->response;
	}

	public function getResponse() {
		// Return actual response object
		if( $this->response === null ) {
			$this->getResponseObject();
		}

		$errors = $this->response->getErrors();

		if ( $this->response->hasErrors() ) {
			#$this->response->removeContent( 'data' );
#			$this->response->setCode( Status::HTTP );
			$this->response->addContent( 'error', $this->response->getErrors()->toArray() );
			// if there is ONLY one error set the response code to the error code
			if( $this->response->hasErrors() === 1 ) {
				$code = $this->response->getErrors()->getFirst()->getErrorCode();
				$this->response->setCode( $code );
			} else {
				// there are more than one error set HTTP_BAD_REQUEST code
				$this->response->setCode( Status::HTTP_BAD_REQUEST );
			}
		}

		if( $this->debug ) {
			$this->response->addContent('debug', $this->debug_messages);
		}

		$this->response->addContent( array(
			'end_point' => $this->getName(),
			'timestamp' => time()
		));

		return $this->response;
	}

	public function getUser() {
		return $this->user;
	}

	/**
	 * Return Symfony form
	 * @param string $formTypeClass
	 * @param \Propel\Runtime\ActiveRecord\ActiveRecordInterface $object
	 * @return mixed
	 */
	protected function getForm( $formTypeClass, $object )
	{
		$validator = Validation::createValidator();

		$formFactory = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension($validator))
			->getFormFactory();

		return $formFactory->createBuilder( $formTypeClass, $object )->getForm();
	}
	protected function validateForm( $formTypeClass, $object, $data ) {
		$response = $this->getResponseObject();

		$form = $this->getForm( $formTypeClass, $object );
		$data = $this->transformRequestData( $data );
		$form->submit( $data );

		if( ! $form->isValid()) {

			//$this->addNormalizedData( "form_errors", $this->getFormErrors( $form ) );

			$response->addContent( "form_errors", $this->getFormErrors( $form ) );

			$response->addContent( "debug", $form->getData()->toArray(), true );

			/*$this->addNormalizedData( "debug", array(
				$form->getData()->toArray()
			) );*/

			return false;

		} else {
			$this->object = $object;
			return true;
		}

	}

	// transform POSTed data to make symfony nested ( embedded ) forms to work
	protected function transformRequestData( array $data = array() ) {

		return $data;
	}

	/**
	 * Return array of form and form child errors
	 * @param $form
	 * @return array  Form Errors
	 */
	protected function getFormErrors( $form ) {

		$errors = $this->_getFormErrors( $form );

		// If there are no extra fields we want to remove
		// this key from response otherwise move it to the end of errors array
		if( empty( $errors['extra_fields'] ) ) {
			unset($errors['extra_fields']);
		} else {
			$extra = $errors['extra_fields'];
			unset($errors['extra_fields']);
			$errors['extra_fields'] = $extra;
		}

		return $errors;
	}

	protected function _getFormErrors( $form, array & $errors = null, $is_child = false ) {
		#$errors = $errors ? $errors: array();
		if( $errors === null ) $errors = array(
			'extra_fields' => array()
		);

		$children = $form->all();

		// Get form field errors
		foreach( $children as $field_name=>$field ) {
			$childForm = false;

			if( count($field->count()) ) {
				$this->_getFormErrors( $field, $errors, true );
				$childForm = true;
			}

			if( $childForm === false ) {

				foreach( $field->getErrors( true ) as $error2 ) {
					#$errors[$field_name][] = $error2->getMessage();
					$errors[ $field->getName() ][] = $error2->getMessage();
				}
			}

		}

		// Get generic form errors
		foreach( $form->getErrors() as $error ) {
			// All forms have names
			// NOTE: form fields implement FormInterface
			$errors[ !$is_child ? 'form' : $form->getName()  ][] = $error->getMessage();

		}

		// Get extra fields POSTed which are not part of the form
		if( count($extra = $form->getExtraData()) ) {

			$errors['extra_fields'] = array_merge( $errors['extra_fields'], $extra );

		}

		return $errors;
	}

	/**
	 * Gets a class name for a given class or instance.
	 *
	 * @param mixed $value
	 *
	 * @return string|bool
	 */
	public function getClass($value)
	{
		if (!is_object($value) && !is_string($value)) {
			return false;
		}

		return ltrim(is_object($value) ? get_class($value) : $value, '\\');
	}

	protected function normalize( $items, array $options = array(), $format = 'json' ) {
		$defaults = array(
			'cache_key' => $this->getName(),
			'groups' => array( self::API_GROUP )
		);
		$options = array_merge($defaults, $options);

		$results = array();

		if( $items instanceof \Traversable) {
			foreach( $items as $item ) {
				$data = $this->_normalize( $item, $options, $format );

				$results[] = $data;
			}
		} else {
			$results = $this->_normalize( $items, $options, $format );
		}


		return $results;
	}

	protected function _normalize( $data, $options = array(), $format = 'json' ) {
		$normalizers = array(
			new DateTimeNormalizer()
		);

		if( $data instanceof ActiveRecordInterface) {
			$meta_factory = new ClassMetadataFactory(new PhpLoader($data));
			$accessor = new PropertyAccessor( true );
			$nameConverter = new PropelNameConverter();
			$normalizer = new PropelNormalizer( $meta_factory,  $nameConverter, $accessor );

			if( isset($options['callbacks']) ){
				$normalizer->setCallbacks( $options['callbacks'] );
			}

			$normalizers[] = $normalizer;
		}


		$encoders = array(new JsonEncoder());
		$serializer = new Serializer( $normalizers, $encoders );

		return $serializer->normalize( $data, $format, $options );
	}

	/**
	 * Currently signed in model
	 * @return \ModelManager\Model
	 */
	public function getModel() {
		$user = $this->getUser();
		$model = \ModelManager\ModelQuery::create()
			->findOneByUserId( $user->getId() );

		return $model;
	}

	public function doAction( $action, $id = null )
	{
		if( $method = $this->supportsAction( $action ) ) {

			call_user_func_array( array( $this, $method ), array( $id ) );

			return $this;
		}

		$this->getResponseObject()->setError( new Error("Unknown operation", Status::HTTP_NOT_FOUND) );

		return $this;
	}

	// check if operation is supported
	protected function supportsAction( $action )
	{
		$action = mb_strtolower($action);
		$map = $this->getOperationsMap();
		if( isset($map[ $action ]) ) {
			return $map[ $action ];
		}

		return false;
	}

	// map of resource actions and their methods
	protected function getOperationsMap()
	{
		return array();
	}

	// add debug message to a response
	protected function add_debug_message( $message ) {
		if(!$this->debug) return;

		$this->debug_messages[] = $message;
	}

	public function getModule( $name, $namespace = "\\Trunk\\Models\\Modules\\", $option = null ) {
		return WibblerDependencyContainer::Instance()->getModule( $name, $namespace, $option );
	}
}