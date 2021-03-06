<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2016                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */
namespace Civi\API;

use Civi\API\Event\AuthorizeEvent;
use Civi\API\Event\PrepareEvent;
use Civi\API\Event\ExceptionEvent;
use Civi\API\Event\ResolveEvent;
use Civi\API\Event\RespondEvent;
use Civi\API\Provider\ProviderInterface;

/**
 * @package Civi
 * @copyright CiviCRM LLC (c) 2004-2016
 */
class Kernel {

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcher
   */
  protected $dispatcher;

  /**
   * @var array<ProviderInterface>
   */
  protected $apiProviders;

  /**
   * @param \Symfony\Component\EventDispatcher\EventDispatcher $dispatcher
   *   The event dispatcher which receives kernel events.
   * @param array $apiProviders
   *   Array of ProviderInterface.
   */
  public function __construct($dispatcher, $apiProviders = array()) {
    $this->apiProviders = $apiProviders;
    $this->dispatcher = $dispatcher;
  }

  /**
   * @deprecated
   * @return array|int
   * @see runSafe
   */
  public function run($entity, $action, $params, $extra = NULL) {
    return $this->runSafe($entity, $action, $params, $extra);
  }

  /**
   * Parse and execute an API request. Any errors will be converted to
   * normal format.
   *
   * @param string $entity
   *   Type of entities to deal with.
   * @param string $action
   *   Create, get, delete or some special action name.
   * @param array $params
   *   Array to be passed to API function.
   * @param mixed $extra
   *   Unused/deprecated.
   *
   * @return array|int
   * @throws \API_Exception
   */
  public function runSafe($entity, $action, $params, $extra = NULL) {
    $apiRequest = Request::create($entity, $action, $params, $extra);

    try {
      $apiResponse = $this->runRequest($apiRequest);
      return $this->formatResult($apiRequest, $apiResponse);
    }
    catch (\Exception $e) {
      $this->dispatcher->dispatch(Events::EXCEPTION, new ExceptionEvent($e, NULL, $apiRequest, $this));

      if ($e instanceof \PEAR_Exception) {
        $err = $this->formatPearException($e, $apiRequest);
      }
      elseif ($e instanceof \API_Exception) {
        $err = $this->formatApiException($e, $apiRequest);
      }
      else {
        $err = $this->formatException($e, $apiRequest);
      }

      return $this->formatResult($apiRequest, $err);
    }
  }

  /**
   * Determine if a hypothetical API call would be authorized.
   *
   * @param string $entity
   *   Type of entities to deal with.
   * @param string $action
   *   Create, get, delete or some special action name.
   * @param array $params
   *   Array to be passed to function.
   * @param mixed $extra
   *   Unused/deprecated.
   *
   * @return bool
   *   TRUE if authorization would succeed.
   * @throws \Exception
   */
  public function runAuthorize($entity, $action, $params, $extra = NULL) {
    $apiProvider = NULL;
    $apiRequest = Request::create($entity, $action, $params, $extra);

    try {
      $this->boot();
      list($apiProvider, $apiRequest) = $this->resolve($apiRequest);
      $this->authorize($apiProvider, $apiRequest);
      return TRUE;
    }
    catch (\Civi\API\Exception\UnauthorizedException $e) {
      return FALSE;
    }
  }

  /**
   * Execute an API request.
   *
   * The request must be in canonical format. Exceptions will be propagated out.
   *
   * @param $apiRequest
   * @return array
   * @throws \API_Exception
   * @throws \Civi\API\Exception\NotImplementedException
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function runRequest($apiRequest) {
    $this->boot($apiRequest);
    $errorScope = \CRM_Core_TemporaryErrorScope::useException();

    list($apiProvider, $apiRequest) = $this->resolve($apiRequest);
    $this->authorize($apiProvider, $apiRequest);
    $apiRequest = $this->prepare($apiProvider, $apiRequest);
    $result = $apiProvider->invoke($apiRequest);

    return $this->respond($apiProvider, $apiRequest, $result);
  }

  /**
   * Bootstrap - Load basic dependencies and sanity-check inputs.
   *
   * @param \Civi\API\V4\Action|array $apiRequest
   * @throws \API_Exception
   */
  public function boot($apiRequest) {
    require_once 'api/Exception.php';

    if (!is_array($apiRequest['params'])) {
      throw new \API_Exception('Input variable `params` is not an array', 2000);
    }
    switch ($apiRequest['version']) {
      case 2:
      case 3:
        require_once 'api/v3/utils.php';
        _civicrm_api3_initialize();
        break;

      case 4:
        // nothing to do
        break;

      default:
        throw new \API_Exception('Unknown api version', 2000);
    }
  }

  /**
   * @param $apiRequest
   * @throws \API_Exception
   */
  protected function validate($apiRequest) {
  }

  /**
   * Determine which, if any, service will execute the API request.
   *
   * @param array $apiRequest
   *   The full description of the API request.
   * @throws Exception\NotImplementedException
   * @return array
   *   Array(0 => ProviderInterface, 1 => array).
   */
  public function resolve($apiRequest) {
    /** @var ResolveEvent $resolveEvent */
    $resolveEvent = $this->dispatcher->dispatch(Events::RESOLVE, new ResolveEvent($apiRequest, $this));
    $apiRequest = $resolveEvent->getApiRequest();
    if (!$resolveEvent->getApiProvider()) {
      throw new \Civi\API\Exception\NotImplementedException("API (" . $apiRequest['entity'] . ", " . $apiRequest['action'] . ") does not exist (join the API team and implement it!)");
    }
    return array($resolveEvent->getApiProvider(), $apiRequest);
  }

  /**
   * Determine if the API request is allowed (under current policy)
   *
   * @param ProviderInterface $apiProvider
   *   The API provider responsible for executing the request.
   * @param array $apiRequest
   *   The full description of the API request.
   * @throws Exception\UnauthorizedException
   */
  public function authorize($apiProvider, $apiRequest) {
    /** @var AuthorizeEvent $event */
    $event = $this->dispatcher->dispatch(Events::AUTHORIZE, new AuthorizeEvent($apiProvider, $apiRequest, $this));
    if (!$event->isAuthorized()) {
      throw new \Civi\API\Exception\UnauthorizedException("Authorization failed");
    }
  }

  /**
   * Allow third-party code to manipulate the API request before execution.
   *
   * @param ProviderInterface $apiProvider
   *   The API provider responsible for executing the request.
   * @param array $apiRequest
   *   The full description of the API request.
   * @return mixed
   */
  public function prepare($apiProvider, $apiRequest) {
    /** @var PrepareEvent $event */
    $event = $this->dispatcher->dispatch(Events::PREPARE, new PrepareEvent($apiProvider, $apiRequest, $this));
    return $event->getApiRequest();
  }

  /**
   * Allow third-party code to manipulate the API response after execution.
   *
   * @param ProviderInterface $apiProvider
   *   The API provider responsible for executing the request.
   * @param array $apiRequest
   *   The full description of the API request.
   * @param array $result
   *   The response to return to the client.
   * @return mixed
   */
  public function respond($apiProvider, $apiRequest, $result) {
    /** @var RespondEvent $event */
    $event = $this->dispatcher->dispatch(Events::RESPOND, new RespondEvent($apiProvider, $apiRequest, $result, $this));
    return $event->getResponse();
  }

  /**
   * @param int $version
   *   API version.
   * @return array
   *   Array<string>.
   */
  public function getEntityNames($version) {
    // Question: Would it better to eliminate $this->apiProviders and just use $this->dispatcher?
    $entityNames = array();
    foreach ($this->getApiProviders() as $provider) {
      /** @var ProviderInterface $provider */
      $entityNames = array_merge($entityNames, $provider->getEntityNames($version));
    }
    $entityNames = array_unique($entityNames);
    sort($entityNames);
    return $entityNames;
  }

  /**
   * @param int $version
   *   API version.
   * @param string $entity
   *   API entity.
   * @return array
   *   Array<string>
   */
  public function getActionNames($version, $entity) {
    // Question: Would it better to eliminate $this->apiProviders and just use $this->dispatcher?
    $actionNames = array();
    foreach ($this->getApiProviders() as $provider) {
      /** @var ProviderInterface $provider */
      $actionNames = array_merge($actionNames, $provider->getActionNames($version, $entity));
    }
    $actionNames = array_unique($actionNames);
    sort($actionNames);
    return $actionNames;
  }

  /**
   * @param \Exception $e
   *   An unhandled exception.
   * @param array $apiRequest
   *   The full description of the API request.
   * @return array
   *   API response.
   */
  public function formatException($e, $apiRequest) {
    $data = array();
    if (!empty($apiRequest['params']['debug'])) {
      $data['trace'] = $e->getTraceAsString();
    }
    return $this->createError($e->getMessage(), $data, $apiRequest, $e->getCode());
  }

  /**
   * @param \API_Exception $e
   *   An unhandled exception.
   * @param array $apiRequest
   *   The full description of the API request.
   * @return array
   *   (API response)
   */
  public function formatApiException($e, $apiRequest) {
    $data = $e->getExtraParams();
    $data['entity'] = \CRM_Utils_Array::value('entity', $apiRequest);
    $data['action'] = \CRM_Utils_Array::value('action', $apiRequest);

    if (\CRM_Utils_Array::value('debug', \CRM_Utils_Array::value('params', $apiRequest))
      && empty($data['trace']) // prevent recursion
    ) {
      $data['trace'] = $e->getTraceAsString();
    }

    return $this->createError($e->getMessage(), $data, $apiRequest, $e->getCode());
  }

  /**
   * @param \PEAR_Exception $e
   *   An unhandled exception.
   * @param array $apiRequest
   *   The full description of the API request.
   * @return array
   *   API response.
   */
  public function formatPearException($e, $apiRequest) {
    $data = array();
    $error = $e->getCause();
    if ($error instanceof \DB_Error) {
      $data["error_code"] = \DB::errorMessage($error->getCode());
      $data["sql"] = $error->getDebugInfo();
    }
    if (!empty($apiRequest['params']['debug'])) {
      if (method_exists($e, 'getUserInfo')) {
        $data['debug_info'] = $error->getUserInfo();
      }
      if (method_exists($e, 'getExtraData')) {
        $data['debug_info'] = $data + $error->getExtraData();
      }
      $data['trace'] = $e->getTraceAsString();
    }
    else {
      $data['tip'] = "add debug=1 to your API call to have more info about the error";
    }

    return $this->createError($e->getMessage(), $data, $apiRequest);
  }

  /**
   * @param string $msg
   *   Descriptive error message.
   * @param array $data
   *   Error data.
   * @param array $apiRequest
   *   The full description of the API request.
   * @param mixed $code
   *   Doesn't appear to be used.
   *
   * @throws \API_Exception
   * @return array
   *   Array<type>.
   */
  public function createError($msg, $data, $apiRequest, $code = NULL) {
    // FIXME what to do with $code?
    if ($msg == 'DB Error: constraint violation' || substr($msg, 0, 9) == 'DB Error:' || $msg == 'DB Error: already exists') {
      try {
        $fields = _civicrm_api3_api_getfields($apiRequest);
        _civicrm_api3_validate_foreign_keys($apiRequest['entity'], $apiRequest['action'], $apiRequest['params'], $fields);
      }
      catch (\Exception $e) {
        $msg = $e->getMessage();
      }
    }

    $data = civicrm_api3_create_error($msg, $data);

    if (isset($apiRequest['params']) && is_array($apiRequest['params']) && !empty($apiRequest['params']['api.has_parent'])) {
      $errorCode = empty($data['error_code']) ? 'chained_api_failed' : $data['error_code'];
      throw new \API_Exception('Error in call to ' . $apiRequest['entity'] . '_' . $apiRequest['action'] . ' : ' . $msg, $errorCode, $data);
    }

    return $data;
  }

  /**
   * @param array $apiRequest
   *   The full description of the API request.
   * @param array $result
   *   The response to return to the client.
   * @return mixed
   */
  public function formatResult($apiRequest, $result) {
    if (isset($apiRequest, $apiRequest['params'])) {
      if (isset($apiRequest['params']['format.is_success']) && $apiRequest['params']['format.is_success'] == 1) {
        return (empty($result['is_error'])) ? 1 : 0;
      }

      if (!empty($apiRequest['params']['format.only_id']) && isset($result['id'])) {
        // FIXME dispatch
        return $result['id'];
      }
    }
    return $result;
  }

  /**
   * @return array<ProviderInterface>
   */
  public function getApiProviders() {
    return $this->apiProviders;
  }

  /**
   * @param array $apiProviders
   *   Array<ProviderInterface>.
   * @return Kernel
   */
  public function setApiProviders($apiProviders) {
    $this->apiProviders = $apiProviders;
    return $this;
  }

  /**
   * @param ProviderInterface $apiProvider
   *   The API provider responsible for executing the request.
   * @return Kernel
   */
  public function registerApiProvider($apiProvider) {
    $this->apiProviders[] = $apiProvider;
    if ($apiProvider instanceof \Symfony\Component\EventDispatcher\EventSubscriberInterface) {
      $this->getDispatcher()->addSubscriber($apiProvider);
    }
    return $this;
  }

  /**
   * @return \Symfony\Component\EventDispatcher\EventDispatcher
   */
  public function getDispatcher() {
    return $this->dispatcher;
  }

  /**
   * @param \Symfony\Component\EventDispatcher\EventDispatcher $dispatcher
   *   The event dispatcher which receives kernel events.
   * @return Kernel
   */
  public function setDispatcher($dispatcher) {
    $this->dispatcher = $dispatcher;
    return $this;
  }

}
