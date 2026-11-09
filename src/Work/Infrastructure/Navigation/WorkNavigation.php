<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;
use App\Work\Infrastructure\Controller\LabelController;
use App\Work\Infrastructure\Controller\MyTasksController;
use App\Work\Infrastructure\Controller\NotificationController;
use App\Work\Infrastructure\Controller\TimeWeekController;

/**
 * The projects in the sidebar for the staff, a task belonging to them too, my
 * tasks with how many are due by today, and the labels of the organisation in
 * the settings. A guest gets my tasks alone: what is shared with them is their
 * start page, which holds their projects and tasks.
 *
 * Time, the week and the report, is the staff's: guests log none.
 *
 * The inbox with how many rows are unread is everyone's, right under the start
 * page at the top: it is where a person begins, as the Inbox under Home in
 * Asana and at the top of Linear, not a part of the projects.
 */
final readonly class WorkNavigation implements NavigationItemProvider
{
    public const int NOTIFICATIONS_CAP = 99;

    public function __construct(private StaffCheck $staff, private MyTasksNavigationCount $count, private NotificationsNavigationCount $notifications)
    {
    }

    public function items(): iterable
    {
        yield new NavigationItem('main', 'nav.item.notifications', 'inbox', NotificationController::ROUTE, $this->notifications, weight: 20, routePrefixes: ['app_notification'], countCap: self::NOTIFICATIONS_CAP);
        if (!$this->staff->isStaff()) {
            yield new NavigationItem('work', 'nav.item.my_tasks', 'square-check-big', MyTasksController::ROUTE, $this->count, weight: 20, routePrefixes: [MyTasksController::ROUTE]);

            return;
        }
        yield new NavigationItem('work', 'nav.item.projects', 'columns-3', 'app_projects', weight: 10, routePrefixes: ['app_project', 'app_task']);
        yield new NavigationItem('work', 'nav.item.my_tasks', 'square-check-big', MyTasksController::ROUTE, $this->count, weight: 20, routePrefixes: [MyTasksController::ROUTE]);
        yield new NavigationItem('work', 'nav.item.time', 'clock-3', TimeWeekController::ROUTE, weight: 30, routePrefixes: [TimeWeekController::ROUTE.'_report', TimeWeekController::ROUTE.'_new']);
        yield new NavigationItem('settings', 'nav.item.labels', 'tag', LabelController::ROUTE, weight: 15, routePrefixes: ['app_label']);
    }
}
