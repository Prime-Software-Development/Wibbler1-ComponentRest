<?php
namespace TrunkSoftware\Component\Rest\Api;

interface EndPointInterface {
	public function setRequest( $request );

	public function getResponse();
}