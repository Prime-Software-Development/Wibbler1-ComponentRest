<?php
namespace Trunk\Component\Rest\Api;

interface EndPointInterface {
	public function setRequest( $request );

	public function getResponse();
}
