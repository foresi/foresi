<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Navigation;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;
use Foresi\Work\Infrastructure\Controller\LabelController;
use Foresi\Work\Infrastructure\Controller\MyTasksController;
use Foresi\Work\Infrastructure\Controller\NotificationController;
use Foresi\Work\Infrastructure\Controller\NotificationSettingsController;
use Foresi\Work\Infrastructure\Controller\SharedWorkController;
use Foresi\Work\Infrastructure\Controller\TaskListController;
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
            yield new NavigationItem(CoreNavigationAreas::OVERVIEW, 'nav.item.shared_with_me', 'share-2', SharedWorkController::ROUTE, weight: 10, routePrefixes: ['app_project', 'app_task']);
        }
        yield new NavigationItem(CoreNavigationAreas::OVERVIEW, 'nav.item.notifications', 'inbox', NotificationController::ROUTE, $this->notifications, weight: 20, routePrefixes: ['app_notification'], countCap: self::NOTIFICATIONS_CAP, marksArea: true);
        // All tasks lead the area, opening on Mine, so the count of my tasks due goes with them; My tasks stays at its address.
        $tasks = new NavigationItem(CoreNavigationAreas::PROJECTS, 'nav.item.tasks', 'square-check-big', TaskListController::ROUTE, $this->count, weight: 5, routePrefixes: [TaskListController::ROUTE, MyTasksController::ROUTE]);
        // How the person is told: their own setting, in their account.
        yield new NavigationItem(NavigationArea::PROFILE, 'shell.user_menu.notifications', 'bell-ring', NotificationSettingsController::ROUTE, weight: 10, section: CoreNavigationAreas::SECTION_NOTIFICATIONS);
        if (!$this->staff->isStaff()) {
            yield $tasks;

            return;
        }
        yield $tasks;
        yield new NavigationItem(CoreNavigationAreas::PROJECTS, 'nav.item.projects', 'columns-3', 'app_projects', weight: 10, routePrefixes: ['app_project', 'app_task']);
        yield new NavigationItem(CoreNavigationAreas::PROJECTS, 'nav.item.time', 'clock-3', TimeWeekController::ROUTE, weight: 30, routePrefixes: [TimeWeekController::ROUTE.'_report', TimeWeekController::ROUTE.'_new']);
        yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.labels', 'tag', LabelController::ROUTE, weight: 15, routePrefixes: ['app_label'], section: CoreNavigationAreas::SECTION_FIRM);
    }
}
