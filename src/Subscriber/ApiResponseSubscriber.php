<?php

namespace MattJanssen\ApiResponseBundle\Subscriber;

use MattJanssen\ApiResponseBundle\Annotation\ApiResponse;
use MattJanssen\ApiResponseBundle\Exception\ApiResponseException;
use MattJanssen\ApiResponseBundle\Model\ApiResponseErrorModel;
use MattJanssen\ApiResponseBundle\Model\ApiResponseResponseModel;
use MattJanssen\ApiResponseBundle\Serializer\Adapter\SerializerAdapterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Subscriber of Kernel Events to Generate an API Response
 *
 * When a non-Response object is returned from a controller action,
 * this subscriber checks for the @ApiResponse annotation and serializes the response.
 *
 * This subscriber also handles exceptions thrown from controller actions with the @ApiResponse annotation.
 *
 * @author Matt Janssen <matt@mattjanssen.com>
 */
class ApiResponseSubscriber implements EventSubscriberInterface
{
    /**
     * Kernel's Debug Status
     *
     * @var bool
     */
    private $debug;

    /**
     * @var SerializerAdapterInterface
     */
    private $serializerAdapter;

    /**
     * Constructor
     *
     * @param SerializerAdapterInterface $serializerAdapter
     * @param bool $debug
     * @internal param string $serializerName
     */
    public function __construct(
        SerializerAdapterInterface $serializerAdapter,
        $debug
    )
    {
        $this->debug = $debug;
        $this->serializerAdapter = $serializerAdapter;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => 'onKernelView',
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    /**
     * Convert Returned Controller Data into a successful API Response
     *
     * This only performs if the @ApiResponse annotation was used on the controller or action.
     *
     * @param GetResponseForControllerResultEvent $event
     */
    public function onKernelView(GetResponseForControllerResultEvent $event)
    {
        $request = $event->getRequest();

        /** @var ApiResponse $configuration */
        $configuration = $request->attributes->get('_api_response');
        if (!$configuration) {
            return;
        }

        $data = $event->getControllerResult();

        $apiResponseModel = (new ApiResponseResponseModel())
            ->setData($data);

        $jsonString = $this->serializerAdapter->serialize($apiResponseModel, $configuration->getGroups());

        $apiResponse = new Response($jsonString, 200, ['Content-Type' => 'application/json']);

        $event->setResponse($apiResponse);
    }

    /**
     * Create a Failed API Response from an Exception
     *
     * This only performs if the @ApiResponse annotation was used on the controller or action.
     *
     * @param GetResponseForExceptionEvent $event
     */
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $request = $event->getRequest();

        /** @var ApiResponse $configuration */
        $configuration = $request->attributes->get('_api_response');
        if (!$configuration) {
            return;
        }

        $exception = $event->getException();

        // Determine the API error code, API error title, and HTTP status code
        // depending on the type of exception thrown.
        if ($exception instanceof ApiResponseException) {
            // The ApiResponseException code gets passed through as the API error code.
            $errorCode = $exception->getCode();

            // There is a separate HTTP status code that can also be set on the exception. The default is 400.
            $httpCode = $exception->getHttpStatusCode();

            // The ApiResponseException message is used at the API error title.
            $errorTitle = $exception->getMessage();
        } elseif ($exception instanceof HttpExceptionInterface) {
            // Use the code from the Symfony HTTP exception as both the API error code and the HTTP status code.
            $errorCode = $exception->getStatusCode();
            $httpCode = $errorCode;

            // Use the corresponding generic HTTP status message as the API error title.
            $errorTitle = Response::$statusTexts[$httpCode];
        } elseif ($exception instanceof AuthenticationException) {
            // Authentication exceptions use 401 for both the API error code and the HTTP status code.
            $errorCode = Response::HTTP_UNAUTHORIZED;
            $httpCode = $errorCode;

            // Use the corresponding generic HTTP status message as the API error title.
            $errorTitle = Response::$statusTexts[$httpCode];
        } else {
            // All other errors use 500 for both the API error code and the HTTP status code.
            $errorCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            $httpCode = $errorCode;

            // The API error title is determined based on the environment.
            if ($this->debug) {
                // For debug environments, exception messages get passed straight through to the client.
                $errorTitle = (string) $exception;
            } else {
                // For non-debug environments, use the corresponding generic HTTP status message as the API error title.
                $errorTitle = Response::$statusTexts[$httpCode];
            }
        }

        $apiErrorModel = (new ApiResponseErrorModel())
            ->setCode($errorCode)
            ->setTitle($errorTitle);

        $apiResponseModel = (new ApiResponseResponseModel())
            ->addError($apiErrorModel);

        $apiResponse = new JsonResponse($apiResponseModel, $httpCode);

        $event->setResponse($apiResponse);
    }
}