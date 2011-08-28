<?php

# Copyright (c) 2011 John Reese
# Licensed under the MIT license

require_once("icon_api.php");

$current_project = helper_get_current_project();
$project_ids = current_user_get_all_accessible_subprojects($current_project);
$project_ids[] = $current_project;

$resolved_threshold = config_get("bug_resolved_status_threshold");

$bug_table = db_get_table("mantis_bug_table");

# Fetch list of target versions in use for the given projects
$query = "SELECT DISTINCT target_version FROM {$bug_table} WHERE project_id IN (" . join(", ", $project_ids) . ") ORDER BY target_version DESC";
$result = db_query_bound($query);

$versions = array();
while ($row = db_fetch_array($result))
{
	if ($row["target_version"])
	{
		$versions[] = $row["target_version"];
	}
}

# Get the selected target version
$target_version = gpc_get_string("version", "");
if (!in_array($target_version, $versions))
{
	$target_version = "";
}

# Fetch list of categories in use for the given projects
$params = array();
$query = "SELECT DISTINCT category_id FROM {$bug_table} WHERE project_id IN (" . join(", ", $project_ids) . ") ";

if ($target_version)
{
	$query .= "AND target_version=" . db_param();
	$params[] = $target_version;
}

$result = db_query_bound($query, $params);
$categories = array();
$category_ids = array();
while ($row = db_fetch_array($result))
{
	if ($row["category_id"])
	{
		$category_id = $row["category_id"];
		$category_ids[] = $category_id;
		$category_name = category_full_name($category_id, false);

		if (isset($categories[$category_name]))
		{
			$categories[$category_name][] = $category_id;
		}
		else
		{
			$categories[$category_name] = array($category_id);
		}
	}
}

# Get the selected category
$category = gpc_get_string("category", "");
if (isset($categories[$category]))
{
	$category_ids = $categories[$category];
}

# Retrieve all bugs with the matching target version
$params = array();
$query = "SELECT id FROM {$bug_table} WHERE project_id IN (" . join(", ", $project_ids) . ")";

if ($target_version)
{
	$query .= " AND target_version=" . db_param();
	$params[] = $target_version;
}
if ($category_name)
{
	$query .= " AND category_id IN (" . join(", ", $category_ids) . ")";
}

$query .= " ORDER BY status ASC, priority DESC, id DESC";
$result = db_query_bound($query, $params);

$bug_ids = array();
while ($row = db_fetch_array($result))
{
	$bug_ids[] = $row["id"];
}

bug_cache_array_rows($bug_ids);
$bugs = array();
$status = array();
$columns = plugin_config_get("board_columns");
$sevcolors = plugin_config_get("board_severity_colors");
$rescolors = plugin_config_get("board_resolution_colors");
$sprint_length = plugin_config_get("sprint_length");

$use_source = plugin_is_loaded("Source");
$resolved_count = 0;

foreach ($bug_ids as $bug_id)
{
	$bug = bug_get($bug_id);
	$bugs[$bug->status][] = $bug;

	$source_count[$bug_id] = $use_source ? count(SourceChangeset::load_by_bug($bug_id)) : "";
	if ($bug->status >= $resolved_threshold)
	{
		$resolved_count++;
	}
}

$bug_count = count($bug_ids);
if ($bug_count > 0)
{
	$resolved_percent = floor(100 * $resolved_count / $bug_count);
}
else
{
	$resolved_percent = 0;
}
$progress_width = min(99, max(8, $resolved_percent));

if ($target_version)
{
	foreach($project_ids as $project_id)
	{
		$version_id = version_get_id($target_version, $project_id, true);
		if ($version_id !== false)
		{
			break;
		}
	}

	$version = version_get($version_id);
	$version_date = $version->date_order;
	$now = time();

	$time_diff = $version_date - $now;
	$time_hours = floor($time_diff / 3600);
	$time_days = floor($time_diff / 86400);
	$time_weeks = floor($time_diff / 604800);

	$timeleft_percent = floor(100 * $time_diff / $sprint_length);
	$timeleft_width = min(99, max(8, $timeleft_percent));

	if ($time_diff <= 0)
	{
		$timeleft_string = plugin_lang_get("time_up");
	}
	else if ($time_weeks > 1)
	{
		$timeleft_string = $time_weeks . plugin_lang_get("time_weeks");
	}
	else if ($time_days > 1)
	{
		$timeleft_string = $time_days . plugin_lang_get("time_days");
	}
	else if ($time_hours > 1)
	{
		$timeleft_string = $time_hours . plugin_lang_get("time_hours");
	}
}

html_page_top(plugin_lang_get("board"));

?>

<link rel="stylesheet" type="text/css" href="<?php echo plugin_file("scrumboard.css") ?>"/>

<br/>
<table class="width100 scrumboard" align="center" cellspacing="1">

<tr>
<td class="form-title" colspan="<?php echo count($columns) ?>">
<?php echo plugin_lang_get("board") ?>
<form action="<?php echo plugin_page("board") ?>" method="get">
<input type="hidden" name="page" value="Scrum/board"/>
<select name="version">
<option value=""><?php echo plugin_lang_get("all") ?></option>
<?php foreach ($versions as $version): ?>
<option value="<?php echo string_attribute($version) ?>" <?php if ($version == $target_version) echo 'selected="selected"' ?>><?php echo string_display_line($version) ?></option>
<?php endforeach ?>
</select>
<select name="category">
<option value=""><?php echo plugin_lang_get("all") ?></option>
<?php foreach (array_keys($categories) as $category_name): ?>
<option value="<?php echo $category_name ?>" <?php if ($category == $category_name) echo 'selected="selected"' ?>><?php echo $category_name ?></option>
<?php endforeach ?>
</select>
<input type="submit" value="Go"/>
</form>
</td>
</tr>

<tr>
<td colspan="<?php echo count($columns) ?>">
<div class="scrumprogress" style="width: <?php echo $progress_width ?>%"><?php echo "{$resolved_count}/{$bug_count} ({$resolved_percent}%)" ?></div>
</td>
</tr>

<?php if ($target_version): ?>
<tr>
<td colspan="<?php echo count($columns) ?>">
<div class="scrumtimeleft" style="width: <?php echo $timeleft_width ?>%"><?php echo $timeleft_string ?></div>
</td>
</tr>
<?php endif ?>

<tr class="row-category">

<?php foreach ($columns as $column => $statuses): ?>
<td><?php echo $column ?></th>
<?php endforeach ?>

</tr>

<tr class="row-1">

<?php foreach ($columns as $column => $statuses): ?>
<td class="scrumcolumn">
<?php $first = true; foreach ($statuses as $status): ?>
<?php if (isset($bugs[$status]) || plugin_config_get("show_empty_status")): ?>
<?php if ($first): $first = false; else: ?>
<hr/>
<?php endif ?>
<?php $status_name = get_enum_element("status", $status); if ($status_name != $column): ?>
<p class="scrumstatus"><?php echo get_enum_element("status", $status) ?></p>
<?php endif ?>
<?php if (isset($bugs[$status])) foreach ($bugs[$status] as $bug):
$sevcolor = $sevcolors[$bug->severity];
$rescolor = $rescolors[$bug->resolution];
?>

<div class="scrumblock">
<p class="priority"><?php print_status_icon($bug->priority) ?></p>
<p class="bugid"></p>
<p class="commits"><?php echo $source_count[$bug->id] ?></p>
<p class="category">
<?php if ($bug->project_id != $current_project) {
	$project_name = project_get_name($bug->project_id);
	echo "<span class=\"project\">{$project_name}</span> - ";
}
echo category_full_name($bug->category_id, false) ?>
</p>
<p class="summary"><?php echo print_bug_link($bug->id) ?>: <?php echo $bug->summary ?></p>
<p class="severity" style="background: <?php echo $sevcolor ?>"></p>
<p class="resolution" style="background: <?php echo $rescolor ?>"></p>
<p class="handler"><?php echo $bug->handler_id > 0 ? user_get_name($bug->handler_id) : "" ?></p>
</div>

<?php endforeach ?>
<?php endif ?>
<?php endforeach ?>
</td>
<?php endforeach ?>

</tr>
</table>

<?php
html_page_bottom();

