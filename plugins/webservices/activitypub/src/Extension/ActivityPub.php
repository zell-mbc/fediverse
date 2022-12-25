<?php
/**
 * @package   FediverseForJoomla
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos
 * @license   https://opensource.org/licenses/GPL-3.0 GPL-3.0-or-later
 */

namespace Joomla\Plugin\WebServices\ActivityPub\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;
use Negotiation\Exception\Exception;
use Negotiation\Negotiator;

class ActivityPub extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   2.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onBeforeApiRoute' => 'registerRoutes',
			'onAfterApiRoute'  => 'fixBrokenJoomlaFormat',
		];
	}

	/**
	 * Workaround for Joomla bug #39495
	 *
	 * @param   Event  $e
	 *
	 * @return  void
	 * @since   2.0.0
	 * @see     https://github.com/joomla/joomla-cms/issues/39495
	 */
	public function fixBrokenJoomlaFormat(Event $e): void
	{
		/** @var ApiApplication $app */
		[$app] = $e->getArguments();

		// Get the 'format' request parameter from the application's input
		if ($app->input->getMethod() === 'POST')
		{
			$format = $app->input->post->get('format', '');
		}
		else
		{
			$format = $app->input->get('format', '');
		}

		// If it's not an array I have nothing to do (not my view or the bug is fixed)
		if (!is_array($format))
		{
			return;
		}

		// At this point the format entries have lost all of their punctuation. Reshape 'em!
		$format = array_map(
			fn($f) => match ($f)
			{
				'applicationactivityjson' => 'application/activity+json',
				'applicationldjson' => 'application/ld+json',
				'applicationvnd.apijson' => 'application/vnd.api+json',
				'applicationjson' => 'application/json',
				default => $f
			},
			$format
		);

		// Re-run the Negotiator
		try
		{
			$negotiator = new Negotiator();
			$mediaType  = $negotiator->getBest($app->input->server->getString('HTTP_ACCEPT'), $format);
			$format     = $mediaType->getValue();
		}
		catch (Exception $e)
		{
			// If an error occurred, fall back to Joomla API Application's default format
			$format = 'application/vnd.api+json';
		}

		// Set the format back to the application
		if ($app->input->getMethod() === 'POST')
		{
			$app->input->post->set('format', $format);
		}
		else
		{
			$app->input->set('format', $format);
		}
	}

	/**
	 * Register the Joomla API application routes for the ActivityPub component
	 *
	 * @param   Event  $e
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	public function registerRoutes(Event $e): void
	{
		/** @var ApiRouter $router */
		[$router] = $e->getArguments();

		$defaults = [
			'component' => 'com_activitypub',
			// Allow public access (do not require Joomla API authentication)
			'public'    => true,
			// Custom accept headers
			'format'    => [
				'application/activity+json',
				'application/ld+json',
				'application/vnd.api+json',
				'application/json',
			],
		];

		$routes = [];

		// Actor -- only supports GET
		$routes[] = new Route(
			['GET'],
			'v1/activitypub/actor/:username',
			'actor.displayItem',
			[
				'username' => '[^/]+',
			],
			$defaults
		);

		// Finally, add the routes to the router.
		$router->addRoutes($routes);
	}
}