<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Navigation;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;
use Foresi\Work\Infrastructure\Controller\LabelController;
use Foresi\Work\Infrastructure\Controller\MyTasksController;
use Foresi\Work\Infrastructure\Controller\NotificationController;
use Foresi\Work\Infrastructure\Controller\SharedWorkController;
use Foresi\Work\Infrastructure\Controller\TimeWeekController;

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

    public function __construct(private StaffCheck $staff, private GuestCheck $guest, private MyTasksNavigationCount $count, private NotificationsNavigationCount $notifications)
    {
    }

    public function items(): iterable
    {
        if ($this->guest->isGuest()) {
            yield new NavigationItem('main', 'nav.item.shared_with_me', 'share-2', SharedWorkController::ROUTE, weight: 10, routePrefixes: ['app_project', 'app_task'], area: CoreNavigationAreas::OVERVIEW);
        }
        yield new NavigationItem('main', 'nav.item.notifications', 'inbox', NotificationController::ROUTE, $this->notifications, weight: 20, routePrefixes: ['app_notification'], countCap: self::NOTIFICATIONS_CAP, area: CoreNavigationAreas::OVERVIEW);
        if (!$this->staff->isStaff()) {
            yield new NavigationItem('work', 'nav.item.my_tasks', 'square-check-big', MyTasksController::ROUTE, $this->count, weight: 20, routePrefixes: [MyTasksController::ROUTE], area: CoreNavigationAreas::PROJECTS);

            return;
        }
        yield new NavigationItem('work', 'nav.item.projects', 'columns-3', 'app_projects', weight: 10, routePrefixes: ['app_project', 'app_task'], area: CoreNavigationAreas::PROJECTS);
        yield new NavigationItem('work', 'nav.item.my_tasks', 'square-check-big', MyTasksController::ROUTE, $this->count, weight: 20, routePrefixes: [MyTasksController::ROUTE], area: CoreNavigationAreas::PROJECTS);
        yield new NavigationItem('work', 'nav.item.time', 'clock-3', TimeWeekController::ROUTE, weight: 30, routePrefixes: [TimeWeekController::ROUTE.'_report', TimeWeekController::ROUTE.'_new'], area: CoreNavigationAreas::PROJECTS);
        yield new NavigationItem('settings', 'nav.item.labels', 'tag', LabelController::ROUTE, weight: 15, routePrefixes: ['app_label'], area: NavigationArea::SETTINGS);
    }
}
