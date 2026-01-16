<?php
/**
 * Costed Inventory Movements Report Hook
 * 
 * Provides backward compatibility for rep308.php by delegating to CostedInventoryMovements service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\CostedInventoryMovements;
use FA\Events\EventDispatcher;

// Note: rep308.php also uses function name inventory_movements()
// This file should not be loaded if using rep307 to avoid function collision
// The legacy rep308.php should be updated to call a unique function name
