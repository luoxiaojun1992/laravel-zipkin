<?php

use Mockery as M;

class TracerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Testing in web container
     *
     * @throws Exception
     */
    public function testWebTrace()
    {
        //Mock QueryExecuted
        M::mock('alias:\Illuminate\Database\Events\QueryExecuted');

        //Mock Event
        $event = M::mock('alias:\\Event');
        $event->shouldReceive('listen')->with(
            'Illuminate\\Database\\Events\\QueryExecuted',
            M::type('\\Closure')
        );
        $event->shouldReceive('listen')->with(
            'Illuminate\\Redis\\Events\\CommandExecuted',
            M::type('\\Closure')
        );

        //Mock App
        $app = M::mock('alias:\\App');
        $app->shouldReceive('runningInConsole')->andReturnFalse();
        $app->shouldReceive('version')
            ->andReturn('5.5.44');

        //Mock Request
        $request = M::mock('\\Illuminate\\Http\\Request');
        $request->shouldReceive('hasHeader')->with('x-b3-sampled')
            ->andReturnFalse();
        $request->shouldReceive('hasHeader')->with('x-b3-flags')
            ->andReturnFalse();
        $request->shouldReceive('hasHeader')->with('x-b3-traceid')
            ->andReturnFalse();
        $request->shouldReceive('hasHeader')->with('x-b3-spanid')
            ->andReturnFalse();
        $request->shouldReceive('hasHeader')->with('x-b3-parentspanid')
            ->andReturnFalse();

        //Mock Request Facade
        $requestFacade = M::mock('alias:\\Illuminate\\Support\\Facades\\Request');
        $requestFacade->shouldReceive('instance')->andReturn($request);

        $tracer = new \Lxj\Laravel\Zipkin\Tracer([
            'sample_rate' => 1,
        ]);

        $this->assertTrue($tracer->rootSpan('unit-test', function (\Zipkin\Span $span) use ($tracer) {
            $this->assertTrue($tracer->span('unit-test-sub', function (\Zipkin\Span $span) {
                return true;
            }, $span->getContext(), \Zipkin\Kind\CLIENT));

            return true;
        }, null, \Zipkin\Kind\SERVER, true));
    }

    /**
     * Testing in console environment
     *
     * @throws Exception
     */
    public function testConsoleTrace()
    {
        //Mock QueryExecuted
        M::mock('alias:\Illuminate\Database\Events\QueryExecuted');

        //Mock Event
        $event = M::mock('alias:\\Event');
        $event->shouldReceive('listen')->with(
            'Illuminate\\Database\\Events\\QueryExecuted',
            M::type('\\Closure')
        );
        $event->shouldReceive('listen')->with(
            'Illuminate\\Redis\\Events\\CommandExecuted',
            M::type('\\Closure')
        );

        //Mock App
        $app = M::mock('alias:\\App');
        $app->shouldReceive('runningInConsole')->andReturnTrue();
        $app->shouldReceive('version')
            ->andReturn('5.5.44');

        $tracer = new \Lxj\Laravel\Zipkin\Tracer([
            'sample_rate' => 1,
        ]);

        $this->assertTrue($tracer->rootSpan('unit-test', function (\Zipkin\Span $span) use ($tracer) {
            $this->assertTrue($tracer->span('unit-test-sub', function (\Zipkin\Span $span) {
                return true;
            }, $span->getContext(), \Zipkin\Kind\CLIENT));

            return true;
        }, null, \Zipkin\Kind\SERVER, true));
    }
}
