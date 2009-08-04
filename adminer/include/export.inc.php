<?php
function dump_table($table, $style, $is_view = false) {
	global $dbh;
	if ($_POST["format"] == "csv") {
		echo "\xef\xbb\xbf"; // UTF-8 byte order mark
		if ($style) {
			dump_csv(array_keys(fields($table)));
		}
	} elseif ($style) {
		$result = $dbh->query("SHOW CREATE TABLE " . idf_escape($table));
		if ($result) {
			if ($style == "DROP+CREATE") {
				echo "DROP " . ($is_view ? "VIEW" : "TABLE") . " IF EXISTS " . idf_escape($table) . ";\n";
			}
			$create = $dbh->result($result, 1);
			$result->free();
			echo ($style != "CREATE+ALTER" ? $create : ($is_view ? substr_replace($create, " OR REPLACE", 6, 0) : substr_replace($create, " IF NOT EXISTS", 12, 0))) . ";\n\n";
		}
		if ($style == "CREATE+ALTER" && !$is_view) {
			// create procedure which iterates over original columns and adds new and removes old
			$query = "SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, COLLATION_NAME, COLUMN_TYPE, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $dbh->quote($table) . " ORDER BY ORDINAL_POSITION";
?>
DELIMITER ;;
CREATE PROCEDURE adminer_alter () BEGIN
	DECLARE _column_name, _collation_name, _column_type, after varchar(64) DEFAULT '';
	DECLARE _column_default longtext;
	DECLARE _is_nullable char(3);
	DECLARE _extra varchar(20);
	DECLARE _column_comment varchar(255);
	DECLARE done, set_after bool DEFAULT 0;
	DECLARE add_columns text DEFAULT '<?php
			$fields = array();
			$result = $dbh->query($query);
			$after = "";
			while ($row = $result->fetch_assoc()) {
				$row["default"] = (isset($row["COLUMN_DEFAULT"]) ? $dbh->quote($row["COLUMN_DEFAULT"]) : "NULL");
				$row["after"] = $dbh->quote($after); //! rgt AFTER lft, lft AFTER id doesn't work
				$row["alter"] = escape_string(idf_escape($row["COLUMN_NAME"])
					. " $row[COLUMN_TYPE]"
					. ($row["COLLATION_NAME"] ? " COLLATE $row[COLLATION_NAME]" : "")
					. (isset($row["COLUMN_DEFAULT"]) ? " DEFAULT $row[default]" : "")
					. ($row["IS_NULLABLE"] == "YES" ? "" : " NOT NULL")
					. ($row["EXTRA"] ? " $row[EXTRA]" : "")
					. ($row["COLUMN_COMMENT"] ? " COMMENT " . $dbh->quote($row["COLUMN_COMMENT"]) : "")
					. ($after ? " AFTER " . idf_escape($after) : " FIRST")
				);
				echo ", ADD $row[alter]";
				$fields[] = $row;
				$after = $row["COLUMN_NAME"];
			}
			$result->free();
			?>';
	DECLARE columns CURSOR FOR <?php echo $query; ?>;
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
	SET @alter_table = '';
	OPEN columns;
	REPEAT
		FETCH columns INTO _column_name, _column_default, _is_nullable, _collation_name, _column_type, _extra, _column_comment;
		IF NOT done THEN
			SET set_after = 1;
			CASE _column_name<?php
			foreach ($fields as $row) {
				echo "
				WHEN " . $dbh->quote($row["COLUMN_NAME"]) . " THEN
					SET add_columns = REPLACE(add_columns, ', ADD $row[alter]', '');
					IF NOT (_column_default <=> $row[default]) OR _is_nullable != '$row[IS_NULLABLE]' OR _collation_name != '$row[COLLATION_NAME]' OR _column_type != '$row[COLUMN_TYPE]' OR _extra != '$row[EXTRA]' OR _column_comment != " . $dbh->quote($row["COLUMN_COMMENT"]) . " OR after != $row[after] THEN
						SET @alter_table = CONCAT(@alter_table, ', MODIFY $row[alter]');
					END IF;"; //! don't replace in comment
			}
			?>

				ELSE
					SET @alter_table = CONCAT(@alter_table, ', DROP ', _column_name);
					SET set_after = 0;
			END CASE;
			IF set_after THEN
				SET after = _column_name;
			END IF;
		END IF;
	UNTIL done END REPEAT;
	CLOSE columns;
	IF @alter_table != '' OR add_columns != '' THEN
		SET @alter_table = CONCAT('ALTER TABLE <?php echo idf_escape($table); ?>', SUBSTR(CONCAT(add_columns, @alter_table), 2));
		PREPARE alter_command FROM @alter_table;
		EXECUTE alter_command;
		DROP PREPARE alter_command;
	END IF;
END;;
DELIMITER ;
CALL adminer_alter;
DROP PROCEDURE adminer_alter;

<?php
			//! indexes
		}
	}
}

function dump_data($table, $style, $select = "") {
	global $dbh, $max_packet;
	if ($style) {
		if ($_POST["format"] != "csv" && $style == "TRUNCATE+INSERT") {
			echo "TRUNCATE " . idf_escape($table) . ";\n";
		}
		$result = $dbh->query(($select ? $select : "SELECT * FROM " . idf_escape($table))); //! enum and set as numbers, binary as _binary, microtime
		if ($result) {
			$fields = fields($table);
			$length = 0;
			while ($row = $result->fetch_assoc()) {
				if ($_POST["format"] == "csv") {
					dump_csv($row);
				} else {
					$insert = "INSERT INTO " . idf_escape($table) . " (" . implode(", ", array_map('idf_escape', array_keys($row))) . ") VALUES";
					$row2 = array();
					foreach ($row as $key => $val) {
						$row2[$key] = (isset($val) ? (ereg('int|float|double|decimal', $fields[$key]["type"]) ? $val : $dbh->quote($val)) : "NULL"); //! columns looking like functions
					}
					$s = implode(",\t", $row2);
					if ($style == "INSERT+UPDATE") {
						$set = array();
						foreach ($row2 as $key => $val) {
							$set[] = idf_escape($key) . " = $val";
						}
						echo "$insert ($s) ON DUPLICATE KEY UPDATE " . implode(", ", $set) . ";\n";
					} else {
						$s = "\n($s)";
						if (!$length) {
							echo $insert . $s;
							$length = strlen($insert) + strlen($s);
						} else {
							$length += 2 + strlen($s); // 2 - separator length
							if ($length < $max_packet) {
								echo ", $s";
							} else {
								echo ";\n$insert$s";
								$length = strlen($insert) + strlen($s);
							}
						}
					}
				}
			}
			if ($_POST["format"] != "csv" && $style != "INSERT+UPDATE" && $result->num_rows) {
				echo ";\n";
			}
			$result->free();
		}
	}
}

function dump_headers($identifier, $multi_table = false) {
	$filename = (strlen($identifier) ? friendly_url($identifier) : "dump");
	$ext = ($_POST["format"] == "sql" ? "sql" : ($multi_table ? "tar" : "csv")); // multiple CSV packed to TAR
	header("Content-Type: " . ($ext == "tar" ? "application/x-tar" : ($ext == "sql" || $_POST["output"] != "file" ? "text/plain" : "text/csv")) . "; charset=utf-8");
	if ($_POST["output"] == "file") {
		header("Content-Disposition: attachment; filename=$filename.$ext");
	}
	return $ext;
}

$dump_output = "<select name='output'><option value='text'>" . lang('open') . "<option value='file'>" . lang('save') . "</select>";
$dump_format = "<select name='format'><option value='sql'>" . lang('SQL') . "<option value='csv'>" . lang('CSV') . "</select>";
$max_packet = 1048576; // default, minimum is 1024
