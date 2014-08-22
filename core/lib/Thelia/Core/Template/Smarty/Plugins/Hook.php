<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Core\Template\Smarty\Plugins;

use Symfony\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\Fragment;
use Thelia\Core\Hook\FragmentBag;
use Thelia\Core\Template\Smarty\AbstractSmartyPlugin;
use Thelia\Core\Template\Smarty\SmartyParser;
use Thelia\Core\Template\Smarty\SmartyPluginDescriptor;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ModuleQuery;

/**
 * Plugin for smarty defining blocks and functions for using Hooks.
 *
 * Class Hook
 * @package Thelia\Core\Template\Smarty\Plugins
 * @author  Julien Chanséaume <jchanseaume@openstudio.fr>
 */
class Hook extends AbstractSmartyPlugin
{

    private $dispatcher;

    /** @var Translator */
    protected $translator;

    /** @var Module */
    protected $smartyPluginModule = null;

    /** @var array */
    protected $hookResults = array();

    /** @var array */
    protected $varstack = array();

    /** @var bool debug */
    protected $debug = false;

    public function __construct($debug, ContainerAwareEventDispatcher $dispatcher)
    {
        $this->debug       = $debug;
        $this->dispatcher  = $dispatcher;
        $this->translator  = $dispatcher->getContainer()->get("thelia.translator");
        $this->hookResults = array();
    }

    /**
     * Generates the content of the hook
     *
     * {hook name="hook_code" var1="value1" var2="value2" ... }
     *
     * This function create an event, feed it with the custom variables passed to the function (var1, var2, ...) and
     * dispatch it to the hooks that respond to it.
     *
     * The name of the event is `hook.{context}.{hook_code}` where :
     *      * context : the id of the context of the smarty render : 1: frontoffice, 2: backoffice, 3: email, 4: pdf
     *      * hook_code : the code of the hook
     *
     * The event collects all the fragments of text rendered in each modules functions that listen to this event.
     * Finally, this fragments are concatenated and injected in the template
     *
     * @param array        $params the params passed in the smarty function
     * @param SmartyParser $smarty the smarty parser
     *
     * @return string the contents generated by modules
     */
    public function processHookFunction($params, &$smarty)
    {
        $hookName   = $this->getParam($params, 'name');
        $module     = intval($this->getParam($params, 'module', 0));
        $moduleCode = intval($this->getParam($params, 'modulecode', 0));

        $type = $smarty->getTemplateDefinition()->getType();

        $event = new HookRenderEvent($hookName, $params);

        $event->setArguments($this->getArgumentsFromParams($params));

        $eventName = sprintf('hook.%s.%s', $type, $hookName);

        // this is a hook specific to a module
        if (0 === $module && "" !== $moduleCode) {
            if (null !== $mod = ModuleQuery::create()->findOneByCode($moduleCode)) {
                $module = $mod->getId();
            }
        }
        if (0 !== $module) {
            $eventName .= '.' . $module;
        }

        $this->getDispatcher()->dispatch($eventName, $event);

        $content = trim($event->dump());

        if ($this->debug && $smarty->getRequest()->get('SHOW_HOOK')) {
            $content = sprintf('<div style="background-color: #C82D26; color: #fff; border-color: #000000; border: solid;">%s</div>%s', $hookName, $content);
        }

        $this->hookResults[$hookName] = $content;

        // support for compatibility with module_include
        if ($type === TemplateDefinition::BACK_OFFICE) {
            $content .= $this->moduleIncludeCompat($params, $smarty);
        }

        return $content;
    }

    /**
     * Call the plugin function module_include for backward compatibility.
     *
     * @param array        $params the params passed in the smarty function
     * @param SmartyParser $smarty the smarty parser
     *
     * @return string the contents generated by module_include function
     */
    protected function moduleIncludeCompat($params, &$smarty)
    {
        $plugin = $this->getSmartyPluginModule();
        $params = array(
            "location" => $this->getParam($params, 'location', null),
            "module"   => $this->getParam($params, 'modulecode', null),
            "countvar" => $this->getParam($params, 'countvar', null)
        );

        return $plugin->theliaModule($params, $smarty);
    }

    /**
     * get the smarty plugin Module
     *
     * @return Module the smarty plugin Module
     */
    protected function getSmartyPluginModule()
    {
        if (null === $this->smartyPluginModule) {
            $this->smartyPluginModule = $this->dispatcher->getContainer()->get("smarty.plugin.module");
        }

        return $this->smartyPluginModule;
    }

    /**
     * Process the content of the hook block.
     *
     * {hookblock name="hook_code" var1="value1" var2="value2" ... }
     *
     * This function create an event, feed it with the custom variables passed to the function (var1, var2, ...) and
     * dispatch it to the hooks that respond to it.
     *
     * The name of the event is `hook.{context}.{hook_code}` where :
     *      * context : the id of the context of the smarty render : 1: frontoffice, 2: backoffice, 3: email, 4: pdf
     *      * hook_code : the code of the hook
     *
     * The event collects all the fragments generated by modules that listen to this event and add it to a fragmentBag.
     * This fragmentBag is not used directly. This is the forhook block that iterates over the fragmentBag to inject
     * data in the template.
     *
     * @param array        $params
     * @param string       $content
     * @param SmartyParser $smarty
     * @param bool         $repeat
     *
     * @return string the generated content
     */
    public function processHookBlock($params, $content, $smarty, &$repeat)
    {

        $hookName = $this->getParam($params, 'name');
        $module   = intval($this->getParam($params, 'module', 0));

        if (!$repeat) {
            if ($this->debug && $smarty->getRequest()->get('SHOW_HOOK')) {
                $content = sprintf('<div style="background-color: #C82D26; color: #fff; border-color: #000000; border: solid;">%s</div>', $hookName)
                    . $content;
            }

            return $content;
        }

        $type = $smarty->getTemplateDefinition()->getType();

        $event = new HookRenderBlockEvent($hookName, $params);

        $event->setArguments($this->getArgumentsFromParams($params));

        $eventName = sprintf('hook.%s.%s', $type, $hookName);

        // this is a hook specific to a module
        if (0 !== $module) {
            $eventName .= '.' . $module;
        }

        $this->getDispatcher()->dispatch($eventName, $event);

        // save results so we can use it in forHook block
        $this->hookResults[$hookName] = $event->get();

    }

    /**
     * Process a {forhook rel="hookname"} ... {/forhook}
     *
     * The forhook iterates over the results return by a hookblock :
     *
     * {hookblock name="product.additional"}
     *      {forhook rel="product.additional"}
     *          <div id="{$id}">
     *              <h2>{$title}</h2>
     *              <p>{$content}</p>
     *          </div>
     *      {/forhook}
     * {/hookblock}
     *
     * @param array        $params
     * @param string       $content
     * @param SmartyParser $smarty
     * @param bool         $repeat
     *
     * @throws \InvalidArgumentException
     * @return string                    the generated content
     */
    public function processForHookBlock($params, $content, $smarty, &$repeat)
    {

        $rel = $this->getParam($params, 'rel');
        if (null == $rel) {
            throw new \InvalidArgumentException(
                $this->translator->trans("Missing 'rel' parameter in forHook arguments")
            );
        }

        /** @var FragmentBag $fragments */
        $fragments = null;

        // first call
        if ($content === null) {
            if (!array_key_exists($rel, $this->hookResults)) {
                throw new \InvalidArgumentException(
                    $this->translator->trans("Related hook name '%name' is not defined.", ['%name' => $rel])
                );
            }

            $fragments = $this->hookResults[$rel];
            $fragments->rewind();

            if ($fragments->isEmpty()) {
                $repeat = false;
            }

        } else {
            $fragments = $this->hookResults[$rel];
            $fragments->next();
        }

        if ($fragments->valid()) {

            /** @var Fragment $fragment */
            $fragment = $fragments->current();

            // On first iteration, save variables that may be overwritten by this hook
            if (!isset($this->varstack[$rel])) {

                $saved_vars = array();

                $varlist = $fragment->getVars();

                foreach ($varlist as $var) {
                    $saved_vars[$var] = $smarty->getTemplateVars($var);
                }

                $this->varstack[$rel] = $saved_vars;
            }

            foreach ($fragment->getVarVal() as $var => $val) {
                $smarty->assign($var, $val);
            }
            // continue iteration
            $repeat = true;
        }

        // end
        if (!$repeat) {
            // Restore previous variables values before terminating
            if (isset($this->varstack[$rel])) {
                foreach ($this->varstack[$rel] as $var => $value) {
                    $smarty->assign($var, $value);
                }

                unset($this->varstack[$rel]);
            }
        }

        if ($content !== null) {
            if ($fragments->isEmpty()) {
                $content = "";
            }

            return $content;
        }

        return '';

    }

    /**
     * Process {elsehook rel="hookname"} ... {/elsehook} block
     *
     * @param array                     $params   hook parameters
     * @param string                    $content  hook text content
     * @param \Smarty_Internal_Template $template the Smarty object
     * @param boolean                   $repeat   repeat indicator (see Smarty doc.)
     *
     * @return string the hook output
     */
    public function elseHook($params, $content, /** @noinspection PhpUnusedParameterInspection */
                             $template, &$repeat)
    {
        // When encountering close tag, check if hook has results.
        if ($repeat === false) {
            return $this->checkEmptyHook($params) ? $content : '';
        }

        return '';
    }

    /**
     * Process {ifhook rel="hookname"} ... {/ifhook} block
     *
     * @param array                     $params   hook parameters
     * @param string                    $content  hook text content
     * @param \Smarty_Internal_Template $template the Smarty object
     * @param boolean                   $repeat   repeat indicator (see Smarty doc.)
     *
     * @return string the hook output
     */
    public function ifHook($params, $content, /** @noinspection PhpUnusedParameterInspection */
                           $template, &$repeat)
    {
        // When encountering close tag, check if hook has results.
        if ($repeat === false) {
            return $this->checkEmptyHook($params) ? '' : $content;
        }

        return '';
    }

    /**
     * Check if a hook has returned results. The hook should have been executed before, or an
     * InvalidArgumentException is thrown
     *
     * @param array $params
     *
     * @return boolean                   true if the hook is empty
     * @throws \InvalidArgumentException
     */
    protected function checkEmptyHook($params)
    {
        $hookName = $this->getParam($params, 'rel');

        if (null == $hookName)
            throw new \InvalidArgumentException(
                $this->translator->trans("Missing 'rel' parameter in ifhook/elsehook arguments")
            );

        if (!isset($this->hookResults[$hookName]))
            throw new \InvalidArgumentException(
                $this->translator->trans("Related hook name '%name' is not defined.", ['%name' => $hookName])
            );

        return (is_string($this->hookResults[$hookName]) && '' === $this->hookResults[$hookName]
            || !is_string($this->hookResults[$hookName]) && $this->hookResults[$hookName]->isEmpty()
        );
    }

    /**
     * Clean the params of the params passed to the hook function or block to feed the arguments of the event
     * with relevant arguments.
     *
     * @param   $params
     *
     * @return array
     */
    protected function getArgumentsFromParams($params)
    {
        $args     = array();
        $excludes = array("name", "before", "separator", "after");

        if (is_array($params)) {
            foreach ($params as $key => $value) {
                if (!in_array($key, $excludes)) {
                    $args[$key] = $value;
                }
            }
        }

        return $args;
    }

    /**
     * Define the various smarty plugins handled by this class
     *
     * @return array an array of smarty plugin descriptors
     */
    public function getPluginDescriptors()
    {
        return array(
            new SmartyPluginDescriptor('function', 'hook', $this, 'processHookFunction'),
            new SmartyPluginDescriptor('block', 'hookblock', $this, 'processHookBlock'),
            new SmartyPluginDescriptor('block', 'forhook', $this, 'processForHookBlock'),
            new SmartyPluginDescriptor('block', 'elsehook', $this, 'elseHook'),
            new SmartyPluginDescriptor('block', 'ifhook', $this, 'ifHook'),
        );
    }

    /**
     * Return the event dispatcher,
     *
     * @return \Symfony\Component\EventDispatcher\EventDispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

}
