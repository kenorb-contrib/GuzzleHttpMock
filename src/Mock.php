<?php


namespace Aeris\GuzzleHttpMock;


use Aeris\GuzzleHttpMock\Exception\CompoundUnexpectedHttpRequestException;
use Aeris\GuzzleHttpMock\Exception\UnexpectedHttpRequestException;
use Aeris\GuzzleHttpMock\Expectation\RequestExpectation;
use Aeris\GuzzleHttpMock\Exception\Exception as HttpMockException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Sets expectations against requests made with the Guzzle Http Client,
 * and mocks responses.
 */
class Mock {

	/** @var \Aeris\GuzzleHttpMock\Expectation\RequestExpectation[] */
	protected $requestExpectations = [];

	/** @var UnexpectedHttpRequestException[] */
	protected $exceptions = [];

	/** @var HandlerStack */
	protected $handlerStack = null;

	public function getHandlerStackWithMiddleware() {
	    if($this->handlerStack == null) {
	        $this->handlerStack = HandlerStack::create($this);
        }
	    return $this->handlerStack;
    }

	public function shouldReceiveRequest(RequestInterface &$request = null) {
		$expectation = new RequestExpectation($request);
		$this->requestExpectations[] = $expectation;

		return $expectation;
	}

	public function verify() {
		$exceptions = $this->exceptions;

		foreach ($this->requestExpectations as $expectation) {
			try {
				$expectation->verify();
			}
			catch (UnexpectedHttpRequestException $ex) {
				$exceptions[] = $ex;
			}
		}

		if (count($exceptions)) {
			throw new CompoundUnexpectedHttpRequestException($exceptions);
		}
	}

	public function __invoke(RequestInterface $request, array $options)
    {
        try {
            $response = $this->makeRequest($request,$options);
        }
        catch (HttpMockException $error) {
            $this->fail($error);

            // Set a stub response.
            // The exception will actually be thrown in
            // `verify()`
            // If we threw the exception here,
            // it would be caught by Guzzle,
            // and wrapped into a RequestException
            return new FulfilledPromise(new Response(200));
        }

        return new FulfilledPromise($response);
    }

	/**
	 * @param RequestInterface $request
	 * @return ResponseInterface
	 * @throws CompoundUnexpectedHttpRequestException
	 */
	private function makeRequest(RequestInterface $request, array $options) {
	    $count = count($this->requestExpectations);
		$state = array_reduce(
			$this->requestExpectations,
			function (array $state, Expectation\RequestExpectation $requestExpectation) use ($request, $options, $count) {
				// We got a successful response -- we're good to go.
				if (isset($state['response'])) {
					return $state;
				}

				// Try to make a request against the expectation
				try {
					$state['response'] = $requestExpectation->makeRequest($request, $options);
				}
				catch (UnexpectedHttpRequestException $error) {
					// Save the error
					$state['errors'][] = $error;
				}

				return $state;
			},
			[
				'response' => null,
				'errors' => []
			]
		);

		if (is_null($state['response'])) {
			$msg = array_reduce($state['errors'], function($msg, \Exception $err) {
				return $msg . PHP_EOL . $err->getMessage();
			}, "No mock matches request `{$request->getMethod()} {".(string)$request->getUri()."}`:");

			throw new UnexpectedHttpRequestException($msg);
		}

		return $state['response'];
	}

	protected function fail(\Exception $error) {
		$this->exceptions[] = $error;
	}
}
