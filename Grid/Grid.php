<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Stanislav Turza <sorien@mail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Sorien\DataGridBundle\Grid;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Sorien\DataGridBundle\Grid\Columns;
use Sorien\DataGridBundle\Grid\Rows;
use Sorien\DataGridBundle\Grid\Action\MassActionInterface;
use Sorien\DataGridBundle\Grid\Action\RowActionInterface;
use Sorien\DataGridBundle\Grid\Column\Column;
use Sorien\DataGridBundle\Grid\Column\MassActionColumn;
use Sorien\DataGridBundle\Grid\Column\ActionsColumn;
use Sorien\DataGridBundle\Grid\Source\Source;

class Grid
{
    const GRID_STATE_SHOW = 0;
    const GRID_STATE_REDIRECT = 1;
    const GRID_STATE_EXPORT = 2;

    const REQUEST_QUERY_MASS_ACTION_ALL_KEYS_SELECTED = '__action_all_keys';
    const REQUEST_QUERY_MASS_ACTION = '__action_id';
    const REQUEST_QUERY_PAGE = '_page';
    const REQUEST_QUERY_LIMIT = '_limit';
    const REQUEST_QUERY_ORDER = '_order';

    /**
     * @var \Symfony\Component\HttpFoundation\Session\Session
     */
    private $session;

    /**
    * @var \Symfony\Component\HttpFoundation\Request
    */
    private $request;

    /**
    * @var \Symfony\Component\Routing\Router
    */
    private $router;

    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    private $container;

    /**
     * @var array
     */
    private $routeParameters;

    /**
     * @var string
     */
    private $routeUrl;

    /**
     * @var string
     */
    private $id;

    /**
     * @var string
     */
    private $hash;

    /**
    * @var \Sorien\DataGridBundle\Grid\Source\Source
    */
    private $source;

    private $totalCount;
    private $page;
    private $limit;
    private $limits;

    /**
    * @var \Sorien\DataGridBundle\Grid\Columns|\Sorien\DataGridBundle\Grid\Column\Column[]
    */
    private $columns;

    /**
    * @var @var \Sorien\DataGridBundle\Grid\Rows
    */
    private $rows;

    /**
     * @var \Sorien\DataGridBundle\Grid\Action\MassAction[]
     */
    private $massActions = array();

    /**
     * @var \Sorien\DataGridBundle\Grid\Action\RowAction[]
     */
    private $rowActions = array();

    /**
     * @var boolean
     */
    private $showFilters = true;

    /**
     * @var boolean
     */
    private $showTitles = true;

    /**
     * @var string
     */
    private $prefixTitle = '';

    /**
     * @param \Symfony\Component\DependencyInjection\Container $container
     * @param string $id set if you are using more then one grid inside controller
     */
    public function __construct($container, $id = '')
    {
        $this->container = $container;

        $this->router = $container->get('router');
        $this->request = $container->get('request');
        $this->session = $this->request->getSession();

        $this->id = $id;
        $this->setLimits(array(20 => '20', 50 => '50', 100 => '100'));

        $this->columns = new Columns($container->get('security.context'));

        $this->routeParameters = $this->request->attributes->all();

        unset($this->routeParameters['_route']);
        unset($this->routeParameters['_controller']);
        unset($this->routeParameters['_route_params']);
    }

    /**
     * Retrieve Data from Session and Request
     *
     * @param string $key
     * @param bool $fromRequest
     * @param bool $fromSession
     * @param mixed $default
     *
     * @return mixed
     */
    private function load($key, $fromRequest = true, $fromSession = true, $default = null)
    {
        $result = $default;

        if ($fromSession && is_array($data = $this->session->get($this->getHash())))
        {
            if (isset($data[$key]))
            {
                $result = $data[$key];
            }
        }

        if ($fromRequest && is_array($data = $this->request->get($this->getHash())))
        {
            if (isset($data[$key]))
            {
                $result = $data[$key];
            }
        }

        return $result;
    }

    /**
     * Store Data to session
     *
     * @param string $key
     * @param mixed $value
     * @param mixed $default
     */
    private function store($key, $value, $default = null)
    {
        $storage = $this->session->has($this->getHash()) ? $this->session->get($this->getHash()) : array();

        if ((key_exists($key, $storage) && $value == null) or ($value == $default))
        {
            unset($storage[$key]);
        }
        else
        {
            $storage[$key] = $value;
        }

        $this->session->set($this->getHash(), $storage);
    }

    /**
     * Returns State of the grid
     *
     * @return int
     */
    public function getState()
    {
        //generate hash
        $this->createHash();

        //store column data
        $this->processFiltersData();

        //execute massActions
        $this->executeMassActions();

        //store grid data
        $this->processGridData();

        //if there are some data from request grid have to be redirected
        $data = $this->request->get($this->getHash());

        if (!empty($data))
        {
            return self::GRID_STATE_REDIRECT;
        }

        return self::GRID_STATE_SHOW;
    }

    /**
     * @deprecated
     * @return bool
     */
    public function isReadyForRedirect()
    {
        return $this->getState() == self::GRID_STATE_REDIRECT;
    }

    /**
     * Generates hash from Controller, Columns and Source
     */
    public function createHash()
    {
        $this->hash = 'grid_'.md5($this->request->get('_controller').$this->columns->getHash().$this->source->getHash().$this->getId());
    }

    /**
     * Returns Current Hash Call it only after getState() method
     *
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * Set and Store Columns data
     *
     * @return void
     */
    private function processFiltersData()
    {
        foreach ($this->columns as $column)
        {
            $data = $this->load($column->getId());

            $column->setData($data);

            $storeData = $column->getData();

            //filter has changed reset page
            if ($data != $storeData)
            {
                $this->store(self::REQUEST_QUERY_PAGE, null);
            }

            $this->store($column->getId(), $storeData);
        }
    }

    /**
     * Set and Store Initial Grid data
     *
     * @return void
     */
    private function processGridData()
    {
        //load limit data
        $limit = $this->load(self::REQUEST_QUERY_LIMIT);
        $this->setLimit($limit);

        $storedLimit = $this->load(self::REQUEST_QUERY_LIMIT, false, true);

        //if new limit is different from last
        if ($storedLimit != null && $limit != $storedLimit)
        {
            $this->store(self::REQUEST_QUERY_PAGE, null);
        }

        //load page data
        $this->setPage($this->load(self::REQUEST_QUERY_PAGE, true, true, 0));

        //load order data
        if (!is_null($order = $this->load(self::REQUEST_QUERY_ORDER)))
        {
            list($columnId, $columnOrder) = explode('|', $order);

            $column = $this->columns->getColumnById($columnId);

            if (!is_null($column))
            {
                $column->setOrder($columnOrder);
            }

            $this->store(self::REQUEST_QUERY_ORDER, $order);
        }

        //store limit data
        $this->store(self::REQUEST_QUERY_LIMIT, $this->getLimit());

        //store page data
        $this->store(self::REQUEST_QUERY_PAGE, $this->getPage(), 0);
    }

    public function executeMassActions()
    {
        $actionId = $this->load(Grid::REQUEST_QUERY_MASS_ACTION, true, false);

        $actionAllKeys = $this->load(Grid::REQUEST_QUERY_MASS_ACTION_ALL_KEYS_SELECTED, true, false);

        $actionKeys = $actionAllKeys == false ? $this->load(MassActionColumn::ID, true, false) : array();

        if ($actionId > -1 && is_array($actionKeys))
        {
            if (array_key_exists($actionId, $this->massActions))
            {
                $action = $this->massActions[$actionId];

                if (is_callable($action->getCallback()))
                {
                    //call closure or static method
                    call_user_func($action->getCallback(), array_keys($actionKeys), $actionAllKeys, $this->session);
                }
                elseif (substr_count($action->getCallback(), ':') == 2)
                {
                    //call controller action
                    $this->container->get('http_kernel')->forward($action->getCallback(), array('primaryKeys' => array_keys($actionKeys), 'allPrimaryKeys' => $actionAllKeys));
                }
                else
                {
                    throw new \RuntimeException(sprintf('Callback %s is not callable or Controller action', $action->getCallback()));
                }
            }
            else
            {
                throw new \OutOfBoundsException(sprintf('Action %s is not defined.', $actionId));
            }
        }
    }

    /**
     * Prepare Grid for Drawing
     *
     * @return Grid
     */
    public function prepare()
    {
        $this->rows = $this->source->execute($this->columns->getIterator(true), $this->page, $this->limit);

        if(!$this->rows instanceof Rows)
        {
            throw new \Exception('Source have to return Rows object.');
        }

        //add row actions column
        if (count($this->rowActions) > 0)
        {
            foreach ($this->rowActions as $column => $rowActions)
            {
                if ($rowAction = $this->columns->hasColumnById($column, true))
                {
                    $rowAction->setRowActions($rowActions);
                }
                else {
                    $this->columns->addColumn(new ActionsColumn($column, 'Actions', $rowActions));
                }
            }
        }

        //add mass actions column
        if (count($this->massActions) > 0)
        {
            $this->columns->addColumn(new MassActionColumn($this->getHash()), 1);
        }

        $primaryColumnId = $this->columns->getPrimaryColumn()->getId();

        foreach ($this->rows as $row)
        {
            foreach ($this->columns as $column)
            {
                $row->setPrimaryField($primaryColumnId);
            }
        }

        //@todo refactor autohide titles when no title is set
        if (!$this->showTitles)
        {
            $this->showTitles = false;
            foreach ($this->columns as $column)
            {
                if (!$this->showTitles) break;

                if ($column->getTitle() != '')
                {
                    $this->showTitles = true;
                    break;
                }
            }
        }

        //get size
        $this->totalCount = $this->source->getTotalCount($this->columns);

        if(!is_int($this->totalCount))
        {
            throw new \Exception(sprintf('Source function getTotalCount need to return integer result, returned: %s', gettype($this->totalCount)));
        }

        return $this;
    }

    public function setSource($source)
    {
        if (!($source instanceof Source))
        {
            throw new \InvalidArgumentException('Supplied Source have to extend Source class.');
        }

        $this->source = $source;

        $this->source->initialise($this->container);

        //get cols from source
        $this->source->getColumns($this->columns);

        return $this;
    }

    public function addColumn($column, $position = 0)
    {
        $this->columns->addColumn($column, $position);

        return $this;
    }

    public function setColumns($columns)
    {
        if(!$columns instanceof Columns)
        {
            throw new \InvalidArgumentException('Supplied object have to extend Columns class.');
        }

        $this->columns = $columns;
        $this->processFiltersData();

        return $this;
    }

    public function getColumns()
    {
        return $this->columns;
    }

    public function addMassAction(MassActionInterface $action)
    {
        $this->massActions[] = $action;

        return $this;
    }

    public function getMassActions()
    {
        return $this->massActions;
    }

    public function addRowAction(RowActionInterface $action)
    {
        $this->rowActions[$action->getColumn()][] = $action;

        return $this;
    }

    public function getRowActions()
    {
        return $this->rowActions;
    }

    public function setRouteParameter($parameter, $value)
    {
        $this->routeParameters[$parameter] = $value;
    }

    public function getRouteParameters()
    {
        return $this->routeParameters;
    }

    public function getRouteUrl()
    {
        if ($this->routeUrl == '')
        {
            $this->routeUrl = $this->router->generate($this->request->get('_route'), $this->getRouteParameters());
        }

        return $this->routeUrl;
    }

    /**
     * @param mixed $limits
     * @return \Sorien\DataGridBundle\Grid\Grid
     */
    public function setLimits($limits)
    {
        if (is_array($limits))
        {
            $this->limits = $limits;
        }
        elseif (is_int($limits))
        {
            $this->limits = array($limits => (string)$limits);
        }
        else
        {
            throw new \InvalidArgumentException('Limit has to be array or integer');
        }

        return $this;
    }

    public function getLimits()
    {
        return $this->limits;
    }

    private function setLimit($limit)
    {
        //check if belongs to limits
        if (key_exists($limit, $this->limits))
        {
            $this->limit = $limit;
        }
        else
        {
            $this->limit = (int)key($this->limits);
        }
    }

    public function getLimit()
    {
        return $this->limit;
    }

    private function setPage($page)
    {
        if ($page < 0)
        {
            $this->page = 0;
        }

        $this->page = $page;

        return $this;
    }

    public function getPage()
    {
        return $this->page;
    }

    public function getRows()
    {
        return $this->rows;
    }

    public function getPageCount()
    {
        return ceil($this->getTotalCount() / $this->getLimit());
    }

    public function getTotalCount()
    {
        return $this->totalCount;
    }

    /**
     * @return bool
     * @todo fix according as isFilterSectionVisible
     */
    public function isTitleSectionVisible()
    {
        return $this->showTitles ;
    }

    public function isFilterSectionVisible()
    {
        if ($this->showFilters == true)
        {
            foreach ($this->columns as $column)
            {
                if ($column->isFilterable())
                {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return bool return true if pager is visible
     */
    public function isPagerSectionVisible()
    {
        $limits = sizeof($this->getLimits());
        return $limits > 1 || ($limits == 0 && $this->getCurrentLimit() < $this->totalCount);
    }

    /**
     * Function will hide all column filters
     */
    public function hideFilters()
    {
        $this->showFilters = false;
    }

    /**
     * function will hide all column titles
     */
    public function hideTitles()
    {
        $this->showTitles = false;
    }

    /**
     * @param Column\Column $extension
     * @return void
     */
    public function addColumnExtension($extension)
    {
        $this->columns->addExtension($extension);
    }

    /**
     * Sets unique filter identification
     *
     * @param $id
     * @return Grid
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function deleteAction($ids)
    {
        $this->source->delete($ids);
    }

    function __clone()
    {
        /**
         * clone all objects
         */
        $this->columns = clone $this->columns;
    }

    /**
     * Renders a view.
     *
     * @param array    $parameters An array of parameters to pass to the view
     * @param string   $view The view name
     * @param Response $response A response instance
     *
     * @return Response A Response instance
     */
    public function gridResponse(array $parameters = array(), $view = null, Response $response = null)
    {
        if ($this->isReadyForRedirect())
        {
            return new RedirectResponse($this->getRouteUrl());
        }
        else
        {
            if (is_null($view))
            {
                return $parameters;
            }
            else
            {
                return $this->container->get('templating')->renderResponse($view, $parameters, $response);
            }
        }
    }

    /**
     * Get Total count of data items
     *
     * @return int
     */
    private function getTotalCountFromData()
    {
        return count($this->data);
    }

    public function getPrefixTitle()
    {
        return $this->prefixTitle;
    }

    public function setPrefixTitle($prefixTitle)
    {
        $this->prefixTitle = $prefixTitle;
        return $this;
    }
}
