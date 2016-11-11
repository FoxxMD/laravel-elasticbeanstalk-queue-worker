<?php


namespace FoxxMD\LaravelElasticBeanstalkQueueWorker;


use Illuminate\Support\ServiceProvider;

class ElasticBeanstalkQueueWorkerProvider extends ServiceProvider  {

	public function boot()
	{
		$this->publishes([
			__DIR__.'/.ebextensions' => base_path('/.ebextensions')
		], 'ebworker');
	}
}