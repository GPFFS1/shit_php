<?php

function getPupilsSSN()
{
    $classes = ["TE22", "TE23", "TE24", "EE22", "EE23", "EE24", "ES22", "ES23", "ES24", "TE4"];
    $pupils = [];
    $schema = file_get_contents("./schema.txt");
    $lines = explode("\n", $schema);

    foreach ($lines as $line) {
        foreach ($classes as $cls) {
            if (substr($line, 0, 4) === $cls) {
                $ssns = array_slice(explode(",", $line), 1);
                foreach ($ssns as $ssn) {
                    $pupils[] = [$cls => $ssn];
                }
            }
        }
    }

    return [$pupils, $lines];
}

function getAllLessons($data, $schema)
{
    $lessons = [];

    foreach ($data as $pupil) {
        foreach ($pupil as $key => $value) {
            $pupil_lessons = [];

            foreach ($schema as $line) {
                if (strpos($line, $value) !== false && $value !== explode("\t", $line)[0]) {
                    $pupil_lessons[] = [$value => explode("\t", $line)[0]];
                }
            }

            $lessons[] = [$key => $pupil_lessons];
        }
    }

    return $lessons;
}

function getPupilNames($data)
{
    $names = [];
    $track_row = 0;
    $schema = file_get_contents("./schema.txt");
    $lines = explode("\n", $schema);

    foreach ($lines as $i => $line) {
        if (strpos($line, "Student") !== false) {
            $track_row = $i + 1;
            break;
        }
    }

    foreach ($data as $pupil) {
        foreach ($pupil as $cls => $value) {
            foreach (array_slice($lines, $track_row) as $line) {
                if (strpos($line, $value) !== false) {
                    $x = array_filter(explode("\t", $line), function ($item) {
                        return $item && strpos($item, "{") === false;
                    });

                    if (isset($x[3]) and isset($x[4])) {
                        $name = $x[3] . " " . $x[4];
                        $names[] = [$value => $name];
                    }
                }
            }
        }
    }

    return $names;
}

function convertToCSV($data, $names, $filename = "pupils_lessons.csv")
{
    $flattened_data = [];

    foreach ($data as $lesson_dict) {
        foreach ($lesson_dict as $cls => $pupil_lessons) {
            foreach ($pupil_lessons as $lesson) {
                foreach ($lesson as $ssn => $lesson_name) {
                    if ($cls == $lesson_name)
                        continue;

                    $name_to_append = "";
                    foreach ($names as $name) {
                        if (isset($name[$ssn])) {
                            $name_to_append = $name[$ssn];
                            break;
                        }
                    }

                    $flattened_data[] = [
                        'kurs' => $cls,
                        'personnummer' => $ssn,
                        'lektion' => $lesson_name,
                        'namn' => $name_to_append
                    ];
                }
            }
        }
    }

    $file = fopen($filename, 'w');
    fputcsv($file, ['kurs', 'personnummer', 'lektion', 'namn']);

    foreach ($flattened_data as $row) {
        fputcsv($file, $row);
    }

    fclose($file);

    return $flattened_data;
}

function formatDaysToLessons($data, $df)
{
    $days = ["ndag", "Tisdag", "Onsdag", "Torsdag", "Fredag"];
    $lessons = [
        "Måndag" => [],
        "Tisdag" => [],
        "Onsdag" => [],
        "Torsdag" => [],
        "Fredag" => []
    ];

    foreach ($data as $line) {
        foreach ($days as $day) {
            if (strpos($line, $day) !== false) {
                foreach ($df as $row) {
                    $lektion = $row['lektion'];
                    if (strpos($line, $lektion) !== false) {
                        $line_data = explode("\t", $line);
                        $p2 = in_array("P2", $line_data);

                        if (!$p2) {
                            $step = false;
                            $old_time = "";

                            foreach ($line_data as $x) {
                                if ($step) {
                                    $step = false;
                                    $new_time = calculateNewTime($old_time, $x);
                                    $lessons[$day][] = [$lektion => [$old_time, $new_time]];
                                    continue;
                                }

                                if (strpos($x, ":") !== false) {
                                    $step = true;
                                    $old_time = $x;
                                    $lessons[$day][] = [$lektion => [$x]];
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }
    }

    return $lessons;
}

function calculateNewTime($old_time, $x)
{
    list($hours, $minutes) = explode(":", $old_time);
    $new_minutes = intval($minutes) + intval($x);

    $new_hours = intval($hours) + intdiv($new_minutes, 60);
    $new_minutes = $new_minutes % 60;

    return sprintf('%02d:%02d', $new_hours, $new_minutes);
}

function convertTimeLessonsToCSV($data, $output_filename)
{
    $flattened_data = [];

    foreach ($data as $day => $lessons) {
        foreach ($lessons as $lesson) {
            foreach ($lesson as $lesson_name => $time) {
                if ($time[1] ?? "") {
                    if ($day == "ndag") {
                        $day = "Måndag";
                    }
                    $flattened_data[] = [
                        'lektion' => $lesson_name,
                        'tid' => implode(",", $time),
                        'dag' => $day
                    ];
                }
            }
        }
    }

    $file = fopen($output_filename, 'w');
    fputcsv($file, ['lektion', 'tid', 'dag']);

    foreach ($flattened_data as $row) {
        fputcsv($file, $row);
    }

    fclose($file);

    return $flattened_data;
}

function createCombinedSchedule()
{
    $days = ["Måndag", "Tisdag", "Onsdag", "Torsdag", "Fredag"];
    $df = array_map('str_getcsv', file('lessons.csv'));

    // Skip the header row
    $header = array_shift($df);

    $schedule = [];
    foreach ($days as $day) {
        $schedule[$day] = [];
    }

    foreach ($df as $row) {
        // Check if the row has at least 3 columns to avoid undefined index errors
        if (count($row) < 3) {
            echo "Malformed CSV row: " . implode(",", $row) . "\n";
            continue;  // Skip this iteration if the row is malformed
        }

        $lesson_name = $row[0];
        $time_info_str = $row[1];
        $day = $row[2];

        // Check if time_info_str is not null or empty
        if ($time_info_str === null || trim($time_info_str) === '') {
            echo "Empty or invalid time_info_str: " . $time_info_str . "\n";
            continue;  // Skip this iteration if time_info_str is invalid
        }

        // Split the time string by comma
        $time_info = explode(",", $time_info_str);

        // Check if we have exactly two time entries (start and end)
        if (count($time_info) == 2) {
            $start_time = strtotime($time_info[0]); // Convert start time to timestamp
            $end_time = $time_info[1]; // End time remains as string

            // Add to schedule
            $schedule[$day][] = [$start_time, $end_time, $lesson_name];
        } else {
            echo "Unexpected time format in line: " . implode(",", $row) . "\n";
        }
    }

    // Sort the lessons by start time for each day
    foreach ($schedule as $day => &$lessons) {
        usort($lessons, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
    }

    // Determine the maximum number of lessons in any day
    $max_rows = 0;
    foreach ($schedule as $day => $lessons) {
        $max_rows = max($max_rows, count($lessons));
    }

    // Create an empty schedule array with placeholders
    $df_schedule = array_fill(0, $max_rows, array_fill_keys($days, ''));

    // Fill the schedule with lessons
    foreach ($schedule as $day => $lessons) {
        foreach ($lessons as $i => $lesson) {
            $start_time_str = date('H:i', $lesson[0]);
            $df_schedule[$i][$day] = $lesson[2] . " (" . $start_time_str . "-" . $lesson[1] . ")";
        }
    }

    // Create output folder if it doesn't exist
    $output_folder = "combined_schedule";
    if (!file_exists($output_folder)) {
        mkdir($output_folder, 0777, true);
    }

    // Write the schedule to a CSV file
    $file = fopen($output_folder . "/schedule.csv", 'w');
    fputcsv($file, $days);
    foreach ($df_schedule as $row) {
        fputcsv($file, $row);
    }
    fclose($file);

    return $df_schedule;
}

function createClassScheduleFromCombinedSchedule($df_schedule)
{
    $class_data = [];
    $days_of_week = ["Måndag", "Tisdag", "Onsdag", "Torsdag", "Fredag"];

    // Read pupil lessons data from CSV
    $pupil_lessons_df = array_map('str_getcsv', file('pupils_lessons.csv'));

    // Process each row of the combined schedule
    foreach ($df_schedule as $row) {
        foreach ($days_of_week as $day) {
            if (isset($row[$day])) {
                $lesson = $row[$day];
                if (empty($lesson)) {
                    continue;
                }

                list($lesson_name, $lesson_time) = explode(" (", rtrim($lesson, ")"));

                foreach ($pupil_lessons_df as $pupil_row) {
                    if (strpos($pupil_row[2], $lesson_name) !== false) {
                        $kurs = $pupil_row[0];

                        if (!isset($class_data[$kurs])) {
                            $class_data[$kurs] = array_fill_keys($days_of_week, []);
                        }

                        // Check if this lesson is already added to avoid duplicates
                        if (!in_array($lesson_time . ": " . $lesson_name, $class_data[$kurs][$day])) {
                            $class_data[$kurs][$day][] = $lesson_time . ": " . $lesson_name;
                        }
                    }
                }
            }
        }
    }

    // Create output folder if it doesn't exist
    $output_folder = "class_schedules";
    if (!file_exists($output_folder)) {
        mkdir($output_folder, 0777, true);
    }

    // Write each class's schedule to a separate CSV file
    foreach ($class_data as $kurs => $days) {
        // Determine the maximum number of lessons in any day to format the CSV correctly
        $max_lessons = max(array_map('count', $days));

        // Prepare the CSV rows
        $csv_rows = [];
        for ($i = 0; $i < $max_lessons; $i++) {
            $row = [];
            foreach ($days_of_week as $day) {
                // If there are more lessons for the day, add it, otherwise add an empty string
                $row[] = isset($days[$day][$i]) ? $days[$day][$i] : '';
            }
            $csv_rows[] = $row;
        }

        // Write to CSV
        $file_path = $output_folder . '/' . $kurs . '.csv';
        $file = fopen($file_path, 'w');
        fputcsv($file, $days_of_week); // Header row
        foreach ($csv_rows as $csv_row) {
            fputcsv($file, $csv_row);
        }
        fclose($file);

        echo "Class schedule saved for: $kurs\n";
    }
}


function main()
{
    list($pupils, $schema) = getPupilsSSN();
    $lessons = getAllLessons($pupils, $schema);
    $names = getPupilNames($pupils);
    $pupils_lessons_df = convertToCSV($lessons, $names);
    $days_to_lessons = formatDaysToLessons($schema, $pupils_lessons_df);
    convertTimeLessonsToCSV($days_to_lessons, "lessons.csv");
    $combined_schedule_df = createCombinedSchedule();
    createClassScheduleFromCombinedSchedule($combined_schedule_df);
}

main();

?>