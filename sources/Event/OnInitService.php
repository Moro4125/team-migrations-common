<?php
/**
 * Event class OnInitService.
 */
namespace Moro\Migration\Event;
use \Symfony\Component\Console\Output\OutputInterface;

/**
 * Class OnInitService
 * @package Moro\Migration\Event
 */
class OnInitService extends AbstractEvent
{
	/**
	 * @var OutputInterface
	 */
	protected $_output;

	/**
	 * @param OutputInterface $output
	 * @return $this
	 */
	public function setOutput(OutputInterface $output)
	{
		assert($this->_output === null);

		$this->_output = $output;
		return $this;
	}

	/**
	 * @return OutputInterface
	 */
	public function getOutput()
	{
		return $this->_output;
	}
}