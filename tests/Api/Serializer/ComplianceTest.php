<?php
/**
 * This is a modified version of original AWS SDK PHP file.
 * https://github.com/aws/aws-sdk-php
 */
namespace Api\Test\Api\Serializer;

use Api\Api\Service;
use Api\Client;
use Api\Test\UsesServiceTrait;

/**
 * @covers Api\Api\Serializer\QuerySerializer
 * @covers Api\Api\Serializer\JsonRpcSerializer
 * @covers Api\Api\Serializer\RestSerializer
 * @covers Api\Api\Serializer\RestJsonSerializer
 * @covers Api\Api\Serializer\RestXmlSerializer
 * @covers Api\Api\Serializer\JsonBody
 * @covers Api\Api\Serializer\XmlBody
 * @covers Api\Api\Serializer\Ec2ParamBuilder
 * @covers Api\Api\Serializer\QueryParamBuilder
 */
class ComplianceTest extends \PHPUnit_Framework_TestCase
{
    use UsesServiceTrait;

    public function testCaseProvider()
    {
        $cases = [];

        $files = glob(__DIR__ . '/../test_cases/protocols/input/*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            foreach ($data as $suite) {
                $suite['metadata']['type'] = $suite['metadata']['protocol'];
                foreach ($suite['cases'] as $case) {
                    $serviceData = [
                        'metadata' => $suite['metadata'],
                        'shapes' => $suite['shapes'],
                        'operations' => [
                            $case['given']['name'] => $case['given']
                        ]
                    ];
                    $description = new Service(
                        $serviceData,
                        function () { return []; }
                    );
                    $cases[] = [
                        $file . ': ' . $suite['description'],
                        $description,
                        $case['given']['name'],
                        isset($case['params']) ? $case['params'] : [],
                        $case['serialized']
                    ];
                }
            }
        }

        return $cases;
    }

    /**
     * @dataProvider testCaseProvider
     */
    public function testPassesComplianceTest(
        $about,
        Service $service,
        $name,
        array $args,
        $serialized
    ) {
        $ep = 'http://us-east-1.foo.amazonaws.com';
        $client = new Client([
            'service'      => 'foo',
            'api_provider' => function () use ($service) {
                return $service->toArray();
            },
            'credentials'  => false,
            'signature'    => $this->getMock('Api\Signature\SignatureInterface'),
            'region'       => 'us-west-2',
            'endpoint'     => $ep,
            'error_parser' => Service::createErrorParser($service->getProtocol()),
            'serializer'   => Service::createSerializer($service, $ep),
            'version'      => 'latest',
            'validate'     => false,
        ]);

        $command = $client->getCommand($name, $args);
        $request = \Api\serialize($command);
        $this->assertEquals($serialized['uri'], $request->getRequestTarget());

        $body = (string) $request->getBody();
        switch ($service->getMetadata('type')) {
            case 'json':
            case 'rest-json':
                // Normalize the JSON data.
                $body = str_replace(':', ': ', $request->getBody());
                $body = str_replace(',', ', ', $body);
                break;
            case 'rest-xml':
                // Normalize XML data.
                if ($serialized['body'] && preg_match('/(\<\/|\/\>)/', $serialized['body'])) {
                    $serialized['body'] = str_replace(
                        ' />',
                        '/>',
                        '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                            . $serialized['body']
                    );
                    $body = trim($body);
                }
                break;
        }

        $this->assertEquals($serialized['body'], $body);

        if (isset($serialized['headers'])) {
            foreach ($serialized['headers'] as $key => $value) {
                $this->assertSame($value, $request->getHeaderLine($key));
            }
        }
    }
}
