<?php


namespace FoxxMD\LaravelElasticBeanstalkQueueWorker;


use Illuminate\Support\ServiceProvider;

class ElasticBeanstalkQueueWorkerProvider extends ServiceProvider  {

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		//nothing to do here!
	}

	public function boot()
	{
		$this->publishes([
			__DIR__.'/.ebextensions' => base_path('/.ebextensions')
		], 'ebworker');
	}
}