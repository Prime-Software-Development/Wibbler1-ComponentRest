<?php
namespace TrunkSoftware\Component\Rest\Api;

use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use ModelManager\Agency;
use ModelManager\User;

use Trunk\Wibbler\WibblerDependencyContainer;
use TrunkSoftware\Component\Errors\Error;
use TrunkSoftware\Component\Http\Request;
use TrunkSoftware\Component\Http\Response;

use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\EventDispatcher\Tests\Service;

use TrunkSoftware\Component\Http\Status;
use TrunkSoftware\Component\Serializer\Normalizer\PropelNormalizer;
use TrunkSoftware\Component\Serializer\Normalizer\DateTimeNormalizer;
use TrunkSoftware\Component\Serializer\Mapping\Loader\PhpLoader;
use TrunkSoftware\Component\Serializer\NameConverter\PropelNameConverter;

abstract class EndPointAbstract implements EndPointInterface, EndPointNameInterface, OptionsInterface
{
	const API_GROUP = 'api';
	const MSG_MISSING_DATA = 'Missing data';
	const MSG_AUTH_FAILED = "Authentication failed";

	/**
	 * @var null|Request
	 */
	protected $request = null;
	/**
	 * @var null|Response
	 */
	protected $response = null;
	protected $options = null;

	// session token
	protected $session_token = null;

	/**
	 * @var null|User
	 */
	protected $user = null;
	/**
	 * @var null|Agency
	 */
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

	/**
	 * @return Request
	 */
	public function getRequest() {
		return $this->request;
	}

	public function setOptions( array $options ) {
		$this->options = $options;

		$this->user = $this->getOption('current_user');
		$this->agency = $this->getOption('current_agency');
		$this->session_token = $this->getOption('session_token');

		return $this;
	}

	public function getOptions()
	{
		return $this->options;
	}

	public function getOption( $name )
	{
		if(isset($this->options[ $name ]))
		{
			return $this->options[ $name ];
		}

		return NULL;
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

		if ( $this->response->hasErrors() ) {
			$this->response->addContent( 'error', $this->response->getErrors()->toArray() );
			// if there are errors and response code is one of success HTTP codes
			// set code to HTTP_BAD_REQUEST
			$code = $this->response->getCode();
			if( Status::HTTP_OK >=  $code && Status::HTTP_MULTIPLE_CHOICES > $code ) {
				$this->response->setCode( Status::HTTP_BAD_REQUEST );
			}
		}

		$this->response->addContent( array(
			'end_point' => $this->getName(),
			'timestamp' => time()
		));

		return $this->response;
	}

	/**
	 * @return User|null
	 */
	public function getUser() {
		return $this->user;
	}

	public function getSessionToken()
	{
		return $this->session_token;
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
	protected function validateForm( $formTypeClass, $object, $data, $clearMissing = true  ) {
		$response = $this->getResponseObject();

		$form = $this->getForm( $formTypeClass, $object );
		$data = $this->transformRequestData( $data );
		$form->submit( $data, $clearMissing );

		if( ! $form->isValid()) {
			$errors = $this->getFormErrors( $form );

			foreach( $errors as $name=>$error ){
				$response->addError( new Error( $error, $name ) );
			}

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