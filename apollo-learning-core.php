
<?php
/**
 * Plugin Name: Apollo Learning Core
 * Description: Custom course, enrollment, student management, and learning functionality for Apollo Photography Academy.
 * Version: 1.2.0
 * Author: Apollo Photography Academy
 * Text Domain: apollo-learning-core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'APOLLO_LEARNING_VERSION', '1.2.0' );
define( 'APOLLO_LEARNING_PATH', plugin_dir_path( __FILE__ ) );
define( 'APOLLO_LEARNING_URL', plugin_dir_url( __FILE__ ) );


/* =========================================================
   1. PLUGIN INITIALIZATION
========================================================= */

function apollo_learning_init() {
    // Reserved for future learning functionality.
}
add_action( 'plugins_loaded', 'apollo_learning_init' );


/* =========================================================
   2. EXCLUSIVE FEATURED COURSE
========================================================= */

function apollo_exclusive_featured_course( $post_id ) {

    if ( ! is_numeric( $post_id ) ) {
        return;
    }

    $post_id = absint( $post_id );

    if (
        get_post_type( $post_id ) !== 'course' ||
        wp_is_post_autosave( $post_id ) ||
        wp_is_post_revision( $post_id )
    ) {
        return;
    }

    $is_featured = get_field( 'featured_course', $post_id, false );

    if ( ! in_array( $is_featured, array( '1', 1, true, 'yes' ), true ) ) {
        return;
    }

    $other_courses = get_posts( array(
        'post_type'      => 'course',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'post__not_in'   => array( $post_id ),
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'featured_course',
                'value'   => '1',
                'compare' => '='
            )
        )
    ) );

    foreach ( $other_courses as $other_course_id ) {
        update_field( 'featured_course', 0, $other_course_id );
    }
}
add_action( 'acf/save_post', 'apollo_exclusive_featured_course', 20 );


/* =========================================================
   3. COURSE WIDGET QUERIES
========================================================= */

function apollo_add_course_featured_query( $query, $value ) {

    $meta_query = $query->get( 'meta_query' );

    if ( ! is_array( $meta_query ) ) {
        $meta_query = array();
    }

    $meta_query[] = array(
        'key'     => 'featured_course',
        'value'   => (string) $value,
        'compare' => '='
    );

    $query->set( 'meta_query', $meta_query );
}

function apollo_featured_courses_widget_query( $query, $widget ) {
    $query->set( 'post_type', 'course' );
    apollo_add_course_featured_query( $query, 1 );
}

add_action(
    'bpfwe/query/apollo_featured_courses',
    'apollo_featured_courses_widget_query',
    10,
    2
);

function apollo_featured_courses_explore( $query, $widget ) {
    $query->set( 'post_type', 'course' );
    apollo_add_course_featured_query( $query, 1 );
}

add_action(
    'bpfwe/query/apollo_featured_courses_explore',
    'apollo_featured_courses_explore',
    10,
    2
);

function apollo_regular_courses_widget_query( $query, $widget ) {
    $query->set( 'post_type', 'course' );
    apollo_add_course_featured_query( $query, 0 );
}

add_action(
    'bpfwe/query/apollo_regular_courses',
    'apollo_regular_courses_widget_query',
    10,
    2
);


/* =========================================================
   4. ENROLLMENT AND PROGRESS DATABASE
========================================================= */

function apollo_create_enrollment_table() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';
    $completion_table = $wpdb->prefix . 'apollo_lesson_progress';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $enrollment_sql = "CREATE TABLE $enrollment_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        course_id bigint(20) unsigned NOT NULL,
        order_id bigint(20) unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'active',
        enrolled_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_course (user_id,course_id),
        KEY order_id (order_id),
        KEY course_id (course_id)
    ) $charset_collate;";

    dbDelta( $enrollment_sql );

    $completion_sql = "CREATE TABLE $completion_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        course_id bigint(20) unsigned NOT NULL,
        lesson_id bigint(20) unsigned NOT NULL,
        completed_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_lesson (user_id,lesson_id),
        KEY course_id (course_id)
    ) $charset_collate;";

        dbDelta( $completion_sql );

    // Store permanent certificate records.
    $certificate_table = $wpdb->prefix . 'apollo_certificates';

    $certificate_sql = "CREATE TABLE $certificate_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        course_id bigint(20) unsigned NOT NULL,
        certificate_code varchar(40) NOT NULL,
        issued_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY certificate_code (certificate_code),
        UNIQUE KEY user_course (user_id,course_id)
    ) $charset_collate;";

    dbDelta( $certificate_sql );

    update_option( 'apollo_enrollment_db_version', '1.0.3' );
}

function apollo_maybe_install_enrollment_table() {

    if ( get_option( 'apollo_enrollment_db_version' ) !== '1.0.3' ) {
        apollo_create_enrollment_table();
    }
}

add_action(
    'plugins_loaded',
    'apollo_maybe_install_enrollment_table',
    20
);


/* =========================================================
   5. ENROLLMENT FUNCTIONS
========================================================= */

function apollo_get_course_by_product( $product_id ) {

    $courses = get_posts( array(
        'post_type'      => 'course',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'course_woocommerce_product',
                'value'   => absint( $product_id ),
                'compare' => '='
            )
        )
    ) );

    return ! empty( $courses ) ? absint( $courses[0] ) : 0;
}

function apollo_enroll_student( $user_id, $course_id, $order_id = 0 ) {

    global $wpdb;

    $user_id  = absint( $user_id );
    $course_id = absint( $course_id );
    $order_id = absint( $order_id );

    $table_name = $wpdb->prefix . 'apollo_enrollments';

    $now = current_time( 'mysql' );

    /*
     * CHECK EXISTING ENROLLMENT
     */

    $existing_status = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT status
            FROM $table_name
            WHERE user_id = %d
            AND course_id = %d
            LIMIT 1",
            $user_id,
            $course_id
        )
    );

    /*
     * CREATE OR RESTORE ENROLLMENT
     */

    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO $table_name
                (user_id, course_id, order_id, status, enrolled_at, updated_at)
            VALUES
                (%d, %d, %d, 'active', %s, %s)
            ON DUPLICATE KEY UPDATE
                order_id = VALUES(order_id),
                status = 'active',
                updated_at = VALUES(updated_at)",
            $user_id,
            $course_id,
            $order_id,
            $now,
            $now
        )
    );

    if ( false === $result ) {
        return false;
    }

    /*
     * SEND NOTIFICATION ONLY FOR NEW
     * OR RESTORED ENROLLMENTS.
     */

    if ( 'active' !== $existing_status ) {

        apollo_add_student_notification(
            $user_id,
            'course_enrollment',
            'Course Enrollment Successful',
            sprintf(
                'You now have access to %s. Start learning today!',
                get_the_title( $course_id )
            ),
            $course_id
        );
    }

    return true;
}

function apollo_user_has_course_access( $user_id, $course_id ) {

    global $wpdb;

    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );

    if ( ! $user_id || ! $course_id ) {
        return false;
    }

    $table_name = $wpdb->prefix . 'apollo_enrollments';

    $enrollment = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
            FROM $table_name
            WHERE user_id = %d
            AND course_id = %d
            AND status = 'active'
            LIMIT 1",
            $user_id,
            $course_id
        )
    );

    return ! empty( $enrollment );
}

function apollo_is_free_preview_lesson( $lesson_id ) {

    $preview = get_field( 'free_preview', $lesson_id );

    return in_array(
        $preview,
        array( '1', 1, true, 'yes' ),
        true
    );
}


/* =========================================================
   6. WOOCOMMERCE ORDER ENROLLMENT
========================================================= */


function apollo_process_course_order( $order_id ) {

    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    $user_id = absint( $order->get_customer_id() );

    if ( ! $user_id ) {

        if ( ! $order->get_meta( '_apollo_guest_enrollment_note' ) ) {

            $order->add_order_note(
                'Apollo enrollment skipped: this order is not associated with a registered customer account.'
            );

            $order->update_meta_data(
                '_apollo_guest_enrollment_note',
                'yes'
            );

            $order->save();
        }

        return;
    }

    $student = get_userdata( $user_id );

    if ( ! $student ) {
        return;
    }

    foreach ( $order->get_items() as $item ) {

        $product_id = absint( $item->get_product_id() );

        if ( ! $product_id ) {
            continue;
        }

        $course_id = apollo_get_course_by_product( $product_id );

        if ( ! $course_id ) {
            continue;
        }

        $enrolled = apollo_enroll_student(
            $user_id,
            $course_id,
            $order_id
        );

        if ( ! $enrolled ) {
            continue;
        }

        /*
         * ENROLLMENT ORDER NOTE
         */

        $note_key = '_apollo_enrollment_note_' . $course_id;

        if ( ! $order->get_meta( $note_key ) ) {

            $order->add_order_note(
                sprintf(
                    'Apollo Learning: customer #%d enrolled in course "%s".',
                    $user_id,
                    get_the_title( $course_id )
                )
            );

            $order->update_meta_data( $note_key, 'yes' );
        }

        /*
         * ENROLLMENT CONFIRMATION EMAIL
         */

        $email_key = '_apollo_enrollment_email_' . $course_id;

        // Avoid sending duplicate emails for the same order and course.
        if ( $order->get_meta( $email_key ) ) {
            continue;
        }

        $student_email = sanitize_email( $student->user_email );

        if ( ! is_email( $student_email ) ) {
            continue;
        }

        $student_name = $student->display_name;
        $course_title = get_the_title( $course_id );
        $course_url = get_permalink( $course_id );

        $subject = sprintf(
            'You are enrolled in %s - Apollo Photography Academy',
            $course_title
        );

        $message = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #252320; line-height: 1.7;">

                <h2 style="color: #171717;">
                    Welcome to Apollo Photography Academy!
                </h2>

                <p>Hello ' . esc_html( $student_name ) . ',</p>

                <p>
                    Great news! Your enrollment has been confirmed.
                    You now have access to:
                </p>

                <h3 style="color: #C77750;">
                    ' . esc_html( $course_title ) . '
                </h3>

                <p>
                    You can start learning whenever you are ready.
                    Visit your course to access the lessons and learning materials.
                </p>

                <p style="margin: 30px 0;">
                    <a href="' . esc_url( $course_url ) . '"
                       style="background: #C77750; color: #ffffff; padding: 14px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;">
                        Start Learning
                    </a>
                </p>

                <p>
                    We are excited to be part of your photography journey.
                </p>

                <p>
                    Best regards,<br>
                    <strong>Apollo Photography Academy</strong>
                </p>

            </div>
        ';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8'
        );

        $email_sent = wp_mail(
            $student_email,
            $subject,
            $message,
            $headers
        );

        if ( $email_sent ) {

            $order->update_meta_data(
                $email_key,
                'yes'
            );
        }
    }

    $order->save();
}


add_action(
    'woocommerce_order_status_processing',
    'apollo_process_course_order'
);

add_action(
    'woocommerce_order_status_completed',
    'apollo_process_course_order'
);

/**
 * Revoke course access when an order is refunded or cancelled.
 *
 * Access is only revoked when:
 * - The enrollment was originally linked to this order.
 * - No other processing/completed order grants access.
 *
 * Manual enrollments are preserved.
 * Lesson progress is never deleted.
 */
function apollo_revoke_course_order_access( $order_id ) {

    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    $user_id = absint( $order->get_customer_id() );

    if ( ! $user_id ) {
        return;
    }

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_enrollments';

    foreach ( $order->get_items() as $item ) {

        $product_id = absint( $item->get_product_id() );

        if ( ! $product_id ) {
            continue;
        }

        $course_id = apollo_get_course_by_product( $product_id );

        if ( ! $course_id ) {
            continue;
        }

        // Retrieve the student's enrollment.
        $enrollment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM $table
                WHERE user_id = %d
                AND course_id = %d
                LIMIT 1",
                $user_id,
                $course_id
            )
        );

        // Do not modify missing or manually created enrollments.
        if (
            ! $enrollment ||
            absint( $enrollment->order_id ) !== absint( $order_id )
        ) {
            continue;
        }

        // Find other valid orders from this customer.
        $other_orders = wc_get_orders( array(
            'customer_id' => $user_id,
            'status'      => array( 'processing', 'completed' ),
            'limit'       => -1,
            'exclude'     => array( absint( $order_id ) ),
            'return'      => 'objects',
        ) );

        $alternative_order_id = 0;

        foreach ( $other_orders as $other_order ) {

            foreach ( $other_order->get_items() as $other_item ) {

                $other_product_id = absint(
                    $other_item->get_product_id()
                );

                if (
                    $other_product_id &&
                    apollo_get_course_by_product( $other_product_id ) === $course_id
                ) {
                    $alternative_order_id = $other_order->get_id();
                    break 2;
                }
            }
        }

        $now = current_time( 'mysql' );

        if ( $alternative_order_id ) {

            // Another valid order exists: keep access active.
            $wpdb->update(
                $table,
                array(
                    'order_id'   => $alternative_order_id,
                    'status'     => 'active',
                    'updated_at' => $now,
                ),
                array(
                    'user_id'   => $user_id,
                    'course_id' => $course_id,
                ),
                array( '%d', '%s', '%s' ),
                array( '%d', '%d' )
            );

            $order->add_order_note(
                sprintf(
                    'Apollo Learning: access to "%s" remains active because another valid order (#%d) grants access.',
                    get_the_title( $course_id ),
                    $alternative_order_id
                )
            );

        } else {

            // No other valid purchase exists: revoke access.
            $wpdb->update(
                $table,
                array(
                    'status'     => 'inactive',
                    'updated_at' => $now,
                ),
                array(
                    'user_id'   => $user_id,
                    'course_id' => $course_id,
                ),
                array( '%s', '%s' ),
                array( '%d', '%d' )
            );

            $order->add_order_note(
                sprintf(
                    'Apollo Learning: access to "%s" has been revoked because order #%d was refunded or cancelled. Lesson progress has been preserved.',
                    get_the_title( $course_id ),
                    $order_id
                )
            );
        }
    }
}

// Revoke access after a refund.
add_action(
    'woocommerce_order_status_refunded',
    'apollo_revoke_course_order_access'
);

// Revoke access after a cancellation.
add_action(
    'woocommerce_order_status_cancelled',
    'apollo_revoke_course_order_access'
);

/* =========================================================
   7. COURSE LESSON RETRIEVAL
========================================================= */

function apollo_get_course_lessons( $course_id ) {

    return new WP_Query( array(
        'post_type'      => 'lesson',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => 'lesson_course',
                'value'   => absint( $course_id ),
                'compare' => '='
            )
        ),
        'meta_key' => 'lesson_order',
        'orderby'  => 'meta_value_num',
        'order'    => 'ASC'
    ) );
}


/* =========================================================
   8. COURSE LESSONS SHORTCODE
========================================================= */

function apollo_course_lessons_shortcode( $atts ) {

    $atts = shortcode_atts(
        array( 'course_id' => 0 ),
        $atts,
        'apollo_course_lessons'
    );

    $course_id = absint( $atts['course_id'] );

    if ( ! $course_id ) {
        $course_id = get_queried_object_id();
    }

    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course'
    ) {
        return '';
    }

    $lessons = apollo_get_course_lessons( $course_id );

    if ( ! $lessons->have_posts() ) {
        return '<p>No lessons are available yet.</p>';
    }

    $user_id = get_current_user_id();

    $has_access = apollo_user_has_course_access(
        $user_id,
        $course_id
    );

    ob_start();

    echo '<div class="apollo-course-lessons">';

    while ( $lessons->have_posts() ) {

        $lessons->the_post();

        $lesson_id = get_the_ID();
        $lesson_order = get_field( 'lesson_order', $lesson_id );
        $is_preview = apollo_is_free_preview_lesson( $lesson_id );

        $is_completed = $user_id
            ? apollo_is_lesson_completed( $user_id, $lesson_id )
            : false;

        echo '<div class="apollo-lesson-item">';

        echo '<span class="apollo-lesson-number">';
        echo esc_html( $lesson_order );
        echo '</span>';

        if ( $is_preview || $has_access ) {

            echo '<a class="apollo-lesson-title" href="';
            echo esc_url( get_permalink( $lesson_id ) );
            echo '">';
            echo esc_html( get_the_title() );
            echo '</a>';

            if ( $is_preview ) {
                echo '<span class="apollo-lesson-preview-label">Free Preview</span>';
            }

            if ( $is_completed ) {
                echo '<span class="apollo-lesson-completed-label">Completed</span>';
            }

        } else {

            echo '<span class="apollo-lesson-title apollo-lesson-locked">';
            echo esc_html( get_the_title() );
            echo '</span>';

            echo '<span class="apollo-lesson-lock-label">Locked</span>';
        }

        echo '</div>';
    }

    echo '</div>';

    wp_reset_postdata();

    return ob_get_clean();
}

add_shortcode(
    'apollo_course_lessons',
    'apollo_course_lessons_shortcode'
);


/* =========================================================
   9. INDIVIDUAL LESSON ACCESS CONTROL
========================================================= */

function apollo_display_lesson_access_message( $course_id ) {

    ob_start();

    echo '<div class="apollo-lesson-access-required">';
    echo '<h2>Lesson Locked</h2>';
    echo '<p>You need to enroll in this course to access this lesson.</p>';

    if ( $course_id ) {
        echo '<p><a href="' . esc_url( get_permalink( $course_id ) ) . '">View Course</a></p>';
    }

    if ( ! is_user_logged_in() ) {
        echo '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in to your account</a></p>';
    }

    echo '</div>';

    return ob_get_clean();
}

function apollo_display_lesson_page( $content ) {

    if (
        ! is_singular( 'lesson' ) ||
        ! in_the_loop() ||
        ! is_main_query()
    ) {
        return $content;
    }

    if ( ! function_exists( 'get_field' ) ) {
        return $content;
    }

    $lesson_id = get_the_ID();

    $course_id = absint(
        get_field( 'lesson_course', $lesson_id )
    );

    $is_preview = apollo_is_free_preview_lesson( $lesson_id );
    $user_id = get_current_user_id();

    $has_access = $course_id
        ? apollo_user_has_course_access( $user_id, $course_id )
        : false;

    if ( ! $is_preview && ! $has_access ) {
        return apollo_display_lesson_access_message( $course_id );
    }

    $video_url    = get_field( 'lesson_video_url', $lesson_id );
    $lesson_order = get_field( 'lesson_order', $lesson_id );
    $resources    = get_field( 'lesson_resources', $lesson_id );

    ob_start();

    echo '<div class="apollo-lesson-page">';

    if ( $course_id && get_post_type( $course_id ) === 'course' ) {
        echo '<div class="apollo-lesson-course">';
        echo '<a href="' . esc_url( get_permalink( $course_id ) ) . '">';
        echo esc_html( get_the_title( $course_id ) );
        echo '</a>';
        echo '</div>';
    }

    echo '<div class="apollo-lesson-information">';

    if ( $lesson_order ) {
        echo '<span class="apollo-lesson-order">';
        echo 'Lesson ' . esc_html( $lesson_order );
        echo '</span>';
    }

    if ( $is_preview ) {
        echo '<span class="apollo-lesson-preview">Free Preview</span>';
    }

    echo '</div>';

    if ( ! empty( $video_url ) ) {

        echo '<div class="apollo-lesson-video">';

        $video_embed = wp_oembed_get( esc_url( $video_url ) );

        if ( $video_embed ) {
            echo $video_embed;
        } else {
            echo '<a href="' . esc_url( $video_url ) . '" target="_blank" rel="noopener noreferrer">Watch Lesson Video</a>';
        }

        echo '</div>';
    }

    if ( ! empty( $content ) ) {
        echo '<div class="apollo-lesson-content">';
        echo $content;
        echo '</div>';
    }

    if ( ! empty( $resources ) ) {

        $attachment_id = absint( $resources );

        if ( $attachment_id ) {

            $resource_name = get_the_title( $attachment_id );

            $download_url = apollo_get_lesson_resource_download_url(
                $lesson_id
            );

            echo '<div class="apollo-lesson-resources">';
            echo '<h3>Lesson Resources</h3>';

            echo '<a href="' . esc_url( $download_url ) . '">';
            echo esc_html( $resource_name );
            echo '</a>';

            echo '</div>';
        }
    }
    if ( $course_id && get_post_type( $course_id ) === 'course' ) {

        $lessons = apollo_get_course_lessons( $course_id );
        $lesson_ids = wp_list_pluck( $lessons->posts, 'ID' );

        $lesson_ids = array_map( 'absint', $lesson_ids );

        $current_index = array_search( $lesson_id, $lesson_ids, true );

        echo '<div class="apollo-lesson-navigation">';

        if ( $current_index !== false ) {

            if ( $current_index > 0 ) {
                $previous_id = $lesson_ids[ $current_index - 1 ];

                echo '<a class="apollo-previous-lesson" href="' . esc_url( get_permalink( $previous_id ) ) . '">';
                echo '&larr; Previous Lesson';
                echo '</a>';
            }

            if ( isset( $lesson_ids[ $current_index + 1 ] ) ) {
                $next_id = $lesson_ids[ $current_index + 1 ];

                echo '<a class="apollo-next-lesson" href="' . esc_url( get_permalink( $next_id ) ) . '">';
                echo 'Next Lesson &rarr;';
                echo '</a>';
            }
        }

        echo '</div>';

        wp_reset_postdata();
    }

    echo '</div>';

    return ob_get_clean();
}

add_filter(
    'the_content',
    'apollo_display_lesson_page',
    20
);


/* =========================================================
   10. LESSON COMPLETION AND LEARNING PROGRESS
========================================================= */

function apollo_is_lesson_completed( $user_id, $lesson_id ) {

    global $wpdb;

    $table_name = $wpdb->prefix . 'apollo_lesson_progress';

    $completed = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
            FROM $table_name
            WHERE user_id = %d
            AND lesson_id = %d
            LIMIT 1",
            absint( $user_id ),
            absint( $lesson_id )
        )
    );

    return ! empty( $completed );
}


function apollo_handle_lesson_completion() {

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to complete a lesson.' );
    }

    $lesson_id = isset( $_POST['lesson_id'] )
        ? absint( wp_unslash( $_POST['lesson_id'] ) )
        : 0;

    if (
        ! $lesson_id ||
        get_post_type( $lesson_id ) !== 'lesson'
    ) {
        wp_die( 'Invalid lesson.' );
    }

    check_admin_referer(
        'apollo_complete_lesson_' . $lesson_id
    );

    $user_id = get_current_user_id();

    $course_id = absint(
        get_field( 'lesson_course', $lesson_id )
    );

    if (
        ! $course_id ||
        ! apollo_user_has_course_access( $user_id, $course_id )
    ) {
        wp_die( 'You do not have access to this lesson.' );
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'apollo_lesson_progress';

    // Check whether this lesson was already completed.
    $already_completed = apollo_is_lesson_completed(
        $user_id,
        $lesson_id
    );

    // Save lesson completion.
    $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO $table_name
                (user_id, course_id, lesson_id, completed_at)
            VALUES
                (%d, %d, %d, %s)",
            $user_id,
            $course_id,
            $lesson_id,
            current_time( 'mysql' )
        )
    );

    // Check course completion only for a newly completed lesson.
    if ( ! $already_completed ) {

        if (
            apollo_user_completed_course(
                $user_id,
                $course_id
            )
        ) {

            // Prevent duplicate course completion notifications.
            $notification_sent = get_user_meta(
                $user_id,
                '_apollo_course_completion_notification_' . $course_id,
                true
            );

            if ( ! $notification_sent ) {

                apollo_add_student_notification(
                    $user_id,
                    'course_completed',
                    'Course Completed!',
                    sprintf(
                        'Congratulations! You have successfully completed %s. Your certificate is ready.',
                        get_the_title( $course_id )
                    ),
                    $course_id
                );

                update_user_meta(
                    $user_id,
                    '_apollo_course_completion_notification_' . $course_id,
                    1
                );
            }
        }
    }

    wp_safe_redirect(
        add_query_arg(
            'apollo_completed',
            '1',
            get_permalink( $lesson_id )
        )
    );

    exit;
}

add_action(
    'admin_post_apollo_complete_lesson',
    'apollo_handle_lesson_completion'
);

function apollo_add_lesson_completion_button( $content ) {

    if (
        ! is_singular( 'lesson' ) ||
        ! in_the_loop() ||
        ! is_main_query() ||
        ! is_user_logged_in()
    ) {
        return $content;
    }

    $lesson_id = get_the_ID();

    $course_id = absint(
        get_field( 'lesson_course', $lesson_id )
    );

    $user_id = get_current_user_id();

    if (
        ! $course_id ||
        ! apollo_user_has_course_access( $user_id, $course_id )
    ) {
        return $content;
    }

    ob_start();

    echo '<div class="apollo-lesson-completion">';

    if ( apollo_is_lesson_completed( $user_id, $lesson_id ) ) {

        echo '<p class="apollo-lesson-completed-message">';
        echo 'You have completed this lesson.';
        echo '</p>';

    } else {

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

        echo '<input type="hidden" name="action" value="apollo_complete_lesson">';
        echo '<input type="hidden" name="lesson_id" value="' . esc_attr( $lesson_id ) . '">';

        echo wp_nonce_field(
            'apollo_complete_lesson_' . $lesson_id,
            '_wpnonce',
            true,
            false
        );

        echo '<button type="submit" class="apollo-complete-lesson-button">';
        echo 'Mark as Complete';
        echo '</button>';

        echo '</form>';
    }

    echo '</div>';

    return $content . ob_get_clean();
}

add_filter(
    'the_content',
    'apollo_add_lesson_completion_button',
    30
);

function apollo_get_course_progress( $user_id, $course_id ) {

    global $wpdb;

    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );

    $lessons = apollo_get_course_lessons( $course_id );
    $total_lessons = (int) $lessons->post_count;

    $table_name = $wpdb->prefix . 'apollo_lesson_progress';

    $completed_lessons = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM $table_name
            WHERE user_id = %d
            AND course_id = %d",
            $user_id,
            $course_id
        )
    );

    $completed_lessons = min( $completed_lessons, $total_lessons );

    $percentage = $total_lessons > 0
        ? round( ( $completed_lessons / $total_lessons ) * 100 )
        : 0;

    return array(
        'total'      => $total_lessons,
        'completed'  => $completed_lessons,
        'percentage' => $percentage
    );
}



/* =========================================================
   11. APOLLO STUDENT DASHBOARD
========================================================= */

/*
 * STUDENT NOTIFICATION CENTER
 */

function apollo_student_notifications_section() {

    if ( ! is_user_logged_in() ) {
        return '';
    }

    global $wpdb;

    $user_id = get_current_user_id();

    $table = $wpdb->prefix . 'apollo_notifications';

    // Count unread notifications.
    $unread_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM $table
            WHERE user_id = %d
            AND is_read = 0",
            $user_id
        )
    );

    // Retrieve the latest 10 notifications.
    $notifications = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
            FROM $table
            WHERE user_id = %d
            ORDER BY created_at DESC
            LIMIT 10",
            $user_id
        )
    );

    ob_start();

    echo '<section class="apollo-dashboard-notifications">';

    echo '<div class="apollo-dashboard-notifications-heading">';

    echo '<div>';

    echo '<h2>Notifications</h2>';

    echo '<p>';

    echo esc_html( $unread_count ) . ' unread notifications';

    echo '</p>';

    echo '</div>';

    if ( $unread_count > 0 ) {

        echo '<form method="post" action="' .
            esc_url( admin_url( 'admin-post.php' ) ) .
            '">';

        echo '<input type="hidden" name="action" value="apollo_mark_all_notifications_read">';

        echo wp_nonce_field(
            'apollo_mark_all_notifications_read',
            '_wpnonce',
            true,
            false
        );

        echo '<button type="submit">';
        echo 'Mark All as Read';
        echo '</button>';

        echo '</form>';
    }

    echo '</div>';

    if ( empty( $notifications ) ) {

        echo '<div class="apollo-dashboard-notifications-empty">';
        echo '<p>You have no notifications yet.</p>';
        echo '</div>';

    } else {

        echo '<div class="apollo-dashboard-notifications-list">';

        foreach ( $notifications as $notification ) {

            $notification_id = absint( $notification->id );

            echo '<div class="apollo-dashboard-notification ';

            echo $notification->is_read
                ? 'is-read'
                : 'is-unread';

            echo '">';

            echo '<div class="apollo-dashboard-notification-content">';

            echo '<h3>' .
                esc_html( $notification->title ) .
                '</h3>';

            echo '<p>' .
                esc_html( $notification->message ) .
                '</p>';

            echo '<span>';

            echo esc_html(
                date_i18n(
                    get_option( 'date_format' ) . ' ' .
                    get_option( 'time_format' ),
                    strtotime( $notification->created_at )
                )
            );

            echo '</span>';

            // Build the relevant destination.
            $notification_url = '';
            $notification_link_text = '';

            $related_id = absint( $notification->related_id );

            if (
                'new_lesson' === $notification->notification_type &&
                get_post_type( $related_id ) === 'lesson'
            ) {

                $notification_url = get_permalink( $related_id );
                $notification_link_text = 'View Lesson';

            } elseif (
                in_array(
                    $notification->notification_type,
                    array(
                        'course_enrollment',
                        'course_completed',
                        'certificate_earned',
                    ),
                    true
                ) &&
                get_post_type( $related_id ) === 'course'
            ) {

                $notification_url = get_permalink( $related_id );

                $notification_link_text = 'View Course';

                if (
                    'certificate_earned' ===
                    $notification->notification_type
                ) {

                    $notification_url = add_query_arg(
                        'apollo_certificate',
                        '1',
                        $notification_url
                    );

                    $notification_link_text = 'View Certificate';
                }
            }

            if ( $notification_url ) {

                echo '<a href="' .
                    esc_url( $notification_url ) .
                    '">';

                echo esc_html( $notification_link_text );

                echo '</a>';
            }

            echo '</div>';

            // Individual mark-as-read action.
            if ( ! $notification->is_read ) {

                echo '<form method="post" action="' .
                    esc_url( admin_url( 'admin-post.php' ) ) .
                    '">';

                echo '<input type="hidden" name="action" value="apollo_mark_notification_read">';

                echo '<input type="hidden" name="notification_id" value="' .
                    esc_attr( $notification_id ) .
                    '">';

                echo wp_nonce_field(
                    'apollo_mark_notification_read_' . $notification_id,
                    '_wpnonce',
                    true,
                    false
                );

                echo '<button type="submit">';
                echo 'Mark as Read';
                echo '</button>';

                echo '</form>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</section>';

    return ob_get_clean();
}


/*
 * MARK ONE NOTIFICATION AS READ
 */

function apollo_handle_mark_notification_read() {

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in.' );
    }

    $notification_id = isset( $_POST['notification_id'] )
        ? absint( wp_unslash( $_POST['notification_id'] ) )
        : 0;

    if ( ! $notification_id ) {
        wp_die( 'Invalid notification.' );
    }

    check_admin_referer(
        'apollo_mark_notification_read_' . $notification_id
    );

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_notifications';

    $wpdb->update(
        $table,
        array( 'is_read' => 1 ),
        array(
            'id'      => $notification_id,
            'user_id' => get_current_user_id(),
        ),
        array( '%d' ),
        array( '%d', '%d' )
    );

    wp_safe_redirect(
        wp_get_referer() ?: home_url( '/' )
    );

    exit;
}

add_action(
    'admin_post_apollo_mark_notification_read',
    'apollo_handle_mark_notification_read'
);


/*
 * MARK ALL NOTIFICATIONS AS READ
 */

function apollo_handle_mark_all_notifications_read() {

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in.' );
    }

    check_admin_referer(
        'apollo_mark_all_notifications_read'
    );

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_notifications';

    $wpdb->update(
        $table,
        array( 'is_read' => 1 ),
        array(
            'user_id' => get_current_user_id(),
            'is_read' => 0,
        ),
        array( '%d' ),
        array( '%d', '%d' )
    );

    wp_safe_redirect(
        wp_get_referer() ?: home_url( '/' )
    );

    exit;
}

add_action(
    'admin_post_apollo_mark_all_notifications_read',
    'apollo_handle_mark_all_notifications_read'
);


function apollo_student_dashboard_shortcode() {

    if ( ! is_user_logged_in() ) {

        $login_url = wp_login_url( get_permalink() );

        return '<div class="apollo-dashboard-login">
            <h2>Student Dashboard</h2>
            <p>Please log in to view your courses.</p>
            <a href="' . esc_url( $login_url ) . '">Log In</a>
        </div>';
    }

    global $wpdb;

    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'apollo_enrollments';

    $enrollments = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT course_id, enrolled_at
            FROM $table_name
            WHERE user_id = %d
            AND status = 'active'
            ORDER BY enrolled_at DESC",
            $user_id
        )
    );

    /*
     * PREPARE DASHBOARD STATISTICS
     */

    $course_rows = array();

    $total_courses = 0;
    $completed_courses_count = 0;
    $total_lessons = 0;
    $total_completed_lessons = 0;

    foreach ( $enrollments as $enrollment ) {

        $course_id = absint( $enrollment->course_id );

        if (
            get_post_type( $course_id ) !== 'course' ||
            get_post_status( $course_id ) !== 'publish'
        ) {
            continue;
        }

        $progress = apollo_get_course_progress(
            $user_id,
            $course_id
        );

        $lesson_count = absint( $progress['total'] );
        $completed_count = absint( $progress['completed'] );

        $progress_percent = isset( $progress['percentage'] )
            ? absint( $progress['percentage'] )
            : 0;

        $lessons = apollo_get_course_lessons( $course_id );

        $continue_lesson_url = '';

        if ( ! empty( $lessons->posts ) ) {

            foreach ( $lessons->posts as $lesson ) {

                $lesson_id = absint( $lesson->ID );

                if (
                    ! apollo_is_lesson_completed(
                        $user_id,
                        $lesson_id
                    )
                ) {
                    $continue_lesson_url = get_permalink( $lesson_id );
                    break;
                }
            }

            if ( ! $continue_lesson_url && $progress_percent >= 100 ) {

                $last_lesson = end( $lessons->posts );

                $continue_lesson_url = get_permalink(
                    absint( $last_lesson->ID )
                );
            }
        }

        $is_completed = apollo_user_completed_course(
            $user_id,
            $course_id
        );

        if ( $is_completed ) {
            $completed_courses_count++;
        }

        $total_courses++;
        $total_lessons += $lesson_count;
        $total_completed_lessons += $completed_count;

        $course_rows[] = array(
            'course_id'          => $course_id,
            'progress'           => $progress_percent,
            'total'              => $lesson_count,
            'completed'          => $completed_count,
            'continue_url'       => $continue_lesson_url,
            'is_completed'       => $is_completed,
        );
    }

    $overall_progress = $total_lessons > 0
        ? round(
            ( $total_completed_lessons / $total_lessons ) * 100
        )
        : 0;

    ob_start();

    echo '<div class="apollo-student-dashboard">';

    /*
     * DASHBOARD HEADER
     */

    echo '<div class="apollo-dashboard-header">';

    echo '<h2>Welcome, ' .
        esc_html( wp_get_current_user()->display_name ) .
        '</h2>';

    echo '<p>Continue your photography learning journey.</p>';

    echo '</div>';

        /*
     * STUDENT NOTIFICATIONS
     */

    echo apollo_student_notifications_section();
    /*
     * DASHBOARD OVERVIEW
     */

    echo '<section class="apollo-dashboard-overview">';

    echo '<h2>Learning Overview</h2>';

    echo '<div class="apollo-dashboard-stats">';

    $stats = array(
        array(
            'label' => 'Enrolled Courses',
            'value' => $total_courses,
        ),
        array(
            'label' => 'Completed Courses',
            'value' => $completed_courses_count,
        ),
        array(
            'label' => 'Lessons Completed',
            'value' => $total_completed_lessons,
        ),
        array(
            'label' => 'Overall Progress',
            'value' => $overall_progress . '%',
        ),
    );

    foreach ( $stats as $stat ) {

        echo '<div class="apollo-dashboard-stat">';

        echo '<span class="apollo-dashboard-stat-value">' .
            esc_html( $stat['value'] ) .
            '</span>';

        echo '<span class="apollo-dashboard-stat-label">' .
            esc_html( $stat['label'] ) .
            '</span>';

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="apollo-dashboard-overall-progress">';

    echo '<p>Overall Learning Progress</p>';

    echo '<div class="apollo-progress-bar" role="progressbar"';

    echo ' aria-valuenow="' . esc_attr( $overall_progress ) . '"';
    echo ' aria-valuemin="0" aria-valuemax="100">';

    echo '<div class="apollo-progress-bar-fill" style="width: ' .
        esc_attr( $overall_progress ) .
        '%;"></div>';

    echo '</div>';

    echo '<p>' .
        esc_html( $total_completed_lessons ) .
        ' of ' .
        esc_html( $total_lessons ) .
        ' lessons completed (' .
        esc_html( $overall_progress ) .
        '%)</p>';

    echo '</div>';

    echo '</section>';

    /*
     * ENROLLED COURSES
     */

    if ( empty( $course_rows ) ) {

        echo '<div class="apollo-dashboard-empty">';

        echo '<h3>You have not enrolled in any courses yet.</h3>';

        echo '<p>Explore our courses and start learning today.</p>';

        echo '<a href="' .
            esc_url( get_post_type_archive_link( 'course' ) ) .
            '">Explore Courses</a>';

        echo '</div>';

    } else {

        echo '<section class="apollo-dashboard-section">';

        echo '<h2>My Courses</h2>';

        echo '<div class="apollo-dashboard-courses">';

        foreach ( $course_rows as $course_row ) {

            $course_id = $course_row['course_id'];

            $course_title = get_the_title( $course_id );
            $course_url = get_permalink( $course_id );

            $progress_percent = $course_row['progress'];
            $completed_count = $course_row['completed'];
            $lesson_count = $course_row['total'];

            $continue_lesson_url = $course_row['continue_url'];

            echo '<div class="apollo-dashboard-course">';

            if ( has_post_thumbnail( $course_id ) ) {

                echo '<div class="apollo-dashboard-course-image">';

                echo get_the_post_thumbnail(
                    $course_id,
                    'medium',
                    array( 'alt' => esc_attr( $course_title ) )
                );

                echo '</div>';
            }

            echo '<div class="apollo-dashboard-course-details">';

            echo '<h3>' . esc_html( $course_title ) . '</h3>';

            echo '<p class="apollo-dashboard-progress-text">';

            echo esc_html( $completed_count ) .
                ' of ' .
                esc_html( $lesson_count ) .
                ' lessons completed';

            echo '</p>';

            echo '<div class="apollo-progress-bar" role="progressbar"';

            echo ' aria-valuenow="' . esc_attr( $progress_percent ) . '"';
            echo ' aria-valuemin="0" aria-valuemax="100">';

            echo '<div class="apollo-progress-bar-fill" style="width: ' .
                esc_attr( $progress_percent ) .
                '%;"></div>';

            echo '</div>';

            echo '<p class="apollo-dashboard-progress-percentage">';

            echo esc_html( $progress_percent ) . '% complete';

            echo '</p>';

            echo '<a href="' .
                esc_url( $course_url ) .
                '">View Course</a>';

            if ( $continue_lesson_url ) {

                echo ' <a href="' .
                    esc_url( $continue_lesson_url ) .
                    '">';

                echo $progress_percent >= 100
                    ? 'Review Lessons'
                    : 'Continue Learning';

                echo '</a>';

            } else {

                echo '<p>Lessons are coming soon.</p>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '</section>';
    }

        /*
     * MY SAVED COURSES
     */

    echo apollo_student_saved_courses_section();

    /*
     * RECENT LEARNING ACTIVITY
     */

    $recent_activity = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                lp.lesson_id,
                lp.course_id,
                lp.completed_at,
                lesson_post.post_title AS lesson_title,
                course_post.post_title AS course_title
            FROM {$wpdb->prefix}apollo_lesson_progress AS lp

            INNER JOIN {$wpdb->posts} AS lesson_post
                ON lesson_post.ID = lp.lesson_id
                AND lesson_post.post_type = 'lesson'
                AND lesson_post.post_status = 'publish'

            INNER JOIN {$wpdb->posts} AS course_post
                ON course_post.ID = lp.course_id
                AND course_post.post_type = 'course'
                AND course_post.post_status = 'publish'

            INNER JOIN {$wpdb->prefix}apollo_enrollments AS enrollment
                ON enrollment.user_id = lp.user_id
                AND enrollment.course_id = lp.course_id
                AND enrollment.status = 'active'

            WHERE lp.user_id = %d

            ORDER BY lp.completed_at DESC
            LIMIT 5",
            $user_id
        )
    );

    echo '<section class="apollo-dashboard-activity">';

    echo '<div class="apollo-dashboard-activity-heading">';

    echo '<h2>Recent Learning Activity</h2>';

    echo '<p>Keep track of the lessons you have completed.</p>';

    echo '</div>';

    if ( empty( $recent_activity ) ) {

        echo '<div class="apollo-dashboard-activity-empty">';

        echo '<p>Your completed lessons will appear here.</p>';

        echo '</div>';

    } else {

        echo '<div class="apollo-dashboard-activity-list">';

        foreach ( $recent_activity as $activity ) {

            echo '<div class="apollo-dashboard-activity-item">';

            echo '<div class="apollo-dashboard-activity-details">';

            echo '<h3>' .
                esc_html( $activity->lesson_title ) .
                '</h3>';

            echo '<p>' .
                esc_html( $activity->course_title ) .
                '</p>';

            if ( $activity->completed_at ) {

                echo '<span>';

                echo 'Completed on ' .
                    esc_html(
                        date_i18n(
                            get_option( 'date_format' ),
                            strtotime( $activity->completed_at )
                        )
                    );

                echo '</span>';
            }

            echo '</div>';

            echo '<a href="' .
                esc_url( get_permalink( $activity->course_id ) ) .
                '">Continue Learning</a>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</section>';

    /*
     * MY CERTIFICATES
     */

    echo '<section class="apollo-dashboard-certificates">';

    echo '<div class="apollo-dashboard-certificates-heading">';

    echo '<h2>My Certificates</h2>';

    echo '<p>View and print certificates for the courses you have completed.</p>';

    echo '</div>';

    $completed_courses = array();

    foreach ( $course_rows as $course_row ) {

        if ( $course_row['is_completed'] ) {
            $completed_courses[] = $course_row['course_id'];
        }
    }

    if ( empty( $completed_courses ) ) {

        echo '<div class="apollo-dashboard-certificates-empty">';

        echo '<p>You have not earned any certificates yet.</p>';

        echo '<p>Complete all lessons in a course to unlock your certificate.</p>';

        echo '</div>';

    } else {

        echo '<div class="apollo-dashboard-certificate-list">';

        foreach ( $completed_courses as $course_id ) {

            $course_title = get_the_title( $course_id );

            $certificate_url = add_query_arg(
                'apollo_certificate',
                '1',
                get_permalink( $course_id )
            );

            $completion_date = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MAX(completed_at)
                    FROM {$wpdb->prefix}apollo_lesson_progress
                    WHERE user_id = %d
                    AND course_id = %d",
                    $user_id,
                    $course_id
                )
            );

            echo '<div class="apollo-dashboard-certificate">';

            echo '<div class="apollo-dashboard-certificate-icon">';

            echo '&#127891;';

            echo '</div>';

            echo '<div class="apollo-dashboard-certificate-details">';

            echo '<h3>' . esc_html( $course_title ) . '</h3>';

            if ( $completion_date ) {

                echo '<p>Completed on ';

                echo esc_html(
                    date_i18n(
                        get_option( 'date_format' ),
                        strtotime( $completion_date )
                    )
                );

                echo '</p>';
            }

            echo '</div>';

            echo '<a class="apollo-dashboard-view-certificate" href="' .
                esc_url( $certificate_url ) .
                '">View Certificate</a>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</section>';

    /*
     * ACCOUNT
     */

    echo '<div class="apollo-dashboard-account">';

    echo '<a href="' .
        esc_url( wp_logout_url( home_url( '/' ) ) ) .
        '">Log Out</a>';

    echo '</div>';

    echo '</div>';

    return ob_get_clean();
}

add_shortcode(
    'apollo_student_dashboard',
    'apollo_student_dashboard_shortcode'
);

/* =========================================================
   12. ADMIN MENU AND STUDENT MANAGEMENT
========================================================= */

function apollo_register_student_admin_menu() {

    add_menu_page(
        'Apollo Learning',
        'Apollo Learning',
        'manage_options',
        'apollo-learning',
        'apollo_admin_students_page',
        'dashicons-welcome-learn-more',
        26
    );
    add_submenu_page(
    'apollo-learning',
    'Learning Activity',
    'Learning Activity',
    'manage_options',
    'apollo-learning-activity',
    'apollo_admin_learning_activity_page'
);

    add_submenu_page(
    'apollo-learning',
    'Learning Reports',
    'Learning Reports',
    'manage_options',
    'apollo-learning-reports',
    'apollo_admin_learning_reports_page'
);
    add_submenu_page(
    'apollo-learning',
    'Certificates',
    'Certificates',
    'manage_options',
    'apollo-learning-certificates',
    'apollo_admin_certificates_page'
    );

    add_submenu_page(
        'apollo-learning',
        'Students',
        'Students',
        'manage_options',
        'apollo-learning',
        'apollo_admin_students_page'
    );

    add_submenu_page(
        null,
        'Student Details',
        'Student Details',
        'manage_options',
        'apollo-student-details',
        'apollo_admin_student_details_page'
    );
}

add_action(
    'admin_menu',
    'apollo_register_student_admin_menu'
);


/* =========================================================
   13. ADMIN STUDENT DIRECTORY
========================================================= */

function apollo_admin_students_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    global $wpdb;

    $users_table = $wpdb->users;
    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';

    $search = isset( $_GET['student_search'] )
        ? sanitize_text_field( wp_unslash( $_GET['student_search'] ) )
        : '';

    $per_page = 20;

    $current_page = isset( $_GET['student_page'] )
        ? max( 1, absint( $_GET['student_page'] ) )
        : 1;

    $offset = ( $current_page - 1 ) * $per_page;

    $search_sql = '';
    $search_values = array();

    if ( $search !== '' ) {

        $like = '%' . $wpdb->esc_like( $search ) . '%';

        $search_sql = "
            AND (
                u.display_name LIKE %s
                OR u.user_email LIKE %s
                OR u.user_login LIKE %s
            )
        ";

        $search_values = array( $like, $like, $like );
    }

    $count_sql = "
        SELECT COUNT(DISTINCT u.ID)
        FROM $users_table u
        INNER JOIN $enrollment_table e ON e.user_id = u.ID
        WHERE 1=1
        $search_sql
    ";

    if ( ! empty( $search_values ) ) {
        $total_students = (int) $wpdb->get_var(
            $wpdb->prepare( $count_sql, $search_values )
        );
    } else {
        $total_students = (int) $wpdb->get_var( $count_sql );
    }

    $students_sql = "
        SELECT DISTINCT
            u.ID,
            u.display_name,
            u.user_email,
            u.user_registered
        FROM $users_table u
        INNER JOIN $enrollment_table e ON e.user_id = u.ID
        WHERE 1=1
        $search_sql
        ORDER BY u.display_name ASC
        LIMIT %d OFFSET %d
    ";

    $query_values = array_merge(
        $search_values,
        array( $per_page, $offset )
    );

    $students = $wpdb->get_results(
        $wpdb->prepare( $students_sql, $query_values )
    );

    $total_pages = (int) ceil( $total_students / $per_page );

    ?>
    <div class="wrap">

        <h1>Apollo Learning – Students</h1>

            <p>Manage students who have course enrollment records, including inactive enrollments.</p>
        <hr>

        <p>
            <strong>Total enrolled students:</strong>
            <?php echo esc_html( $total_students ); ?>
        </p>

        <form method="get" style="margin: 20px 0;">

            <input type="hidden" name="page" value="apollo-learning">

            <input
                type="text"
                name="student_search"
                placeholder="Search by name or email..."
                value="<?php echo esc_attr( $search ); ?>"
                style="width: 300px;"
            >

            <button type="submit" class="button button-primary">
                Search Students
            </button>

            <?php if ( $search !== '' ) : ?>
                <a
                    href="<?php echo esc_url( admin_url( 'admin.php?page=apollo-learning' ) ); ?>"
                    class="button"
                >
                    Clear Search
                </a>
            <?php endif; ?>

        </form>

        <table class="widefat fixed striped">

            <thead>
                <tr>
                    <th>Student</th>
                    <th>Email</th>
                    <th>Active Courses</th>
                    <th>Registration Date</th>
                    <th>Details</th>
                </tr>
            </thead>

            <tbody>

                <?php if ( ! empty( $students ) ) : ?>

                    <?php foreach ( $students as $student ) : ?>

                        <?php
                        $course_count = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*)
                                FROM $enrollment_table
                                WHERE user_id = %d
                                AND status = 'active'",
                                $student->ID
                            )
                        );

                        $details_url = add_query_arg(
                            array(
                                'page' => 'apollo-student-details',
                                'student_id' => absint( $student->ID )
                            ),
                            admin_url( 'admin.php' )
                        );
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php echo esc_html( $student->display_name ); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo esc_html( $student->user_email ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $course_count ); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    date_i18n(
                                        get_option( 'date_format' ),
                                        strtotime( $student->user_registered )
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <a
                                    href="<?php echo esc_url( $details_url ); ?>"
                                    class="button"
                                >
                                    View Details
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else : ?>

                    <tr>
                        <td colspan="5">No enrolled students found.</td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

        <?php if ( $total_pages > 1 ) : ?>

            <div style="margin-top: 20px;">

                <?php
                echo wp_kses_post(
                    paginate_links( array(
                        'base' => add_query_arg(
                            array(
                                'page' => 'apollo-learning',
                                'student_search' => $search,
                                'student_page' => '%#%',
                            ),
                            admin_url( 'admin.php' )
                        ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo; Previous',
                        'next_text' => 'Next &raquo;',
                    ) )
                );
                ?>

            </div>

        <?php endif; ?>

    </div>
    <?php
}


/* =========================================================
   14. INDIVIDUAL STUDENT DETAILS
========================================================= */

function apollo_admin_student_details_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    global $wpdb;

    $student_id = isset( $_GET['student_id'] )
        ? absint( $_GET['student_id'] )
        : 0;

    if ( ! $student_id ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Invalid student ID.</p></div></div>';
        return;
    }

    $student = get_userdata( $student_id );

    if ( ! $student ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Student not found.</p></div></div>';
        return;
    }
    /*
 * HANDLE STUDENT PROFILE UPDATE
 */

$profile_update_message = '';

if (
    isset( $_POST['apollo_update_student_profile'] )
    && $_POST['apollo_update_student_profile'] === '1'
) {

    check_admin_referer(
        'apollo_update_student_profile_' . $student_id
    );

    $new_name = isset( $_POST['student_display_name'] )
        ? sanitize_text_field( wp_unslash( $_POST['student_display_name'] ) )
        : '';

    $new_email = isset( $_POST['student_email'] )
        ? sanitize_email( wp_unslash( $_POST['student_email'] ) )
        : '';

    $new_password = isset( $_POST['student_password'] )
        ? (string) wp_unslash( $_POST['student_password'] )
        : '';

    if ( empty( $new_name ) || empty( $new_email ) ) {

        $profile_update_message = 'error:Name and email are required.';

    } elseif ( ! is_email( $new_email ) ) {

        $profile_update_message = 'error:Please enter a valid email address.';

    } else {

        $user_data = array(
            'ID'           => $student_id,
            'display_name' => $new_name,
            'user_email'   => $new_email,
        );

        if ( ! empty( $new_password ) ) {

            if ( strlen( $new_password ) < 12 ) {

                $profile_update_message = 'error:Password must be at least 12 characters long.';

            } else {

                $user_data['user_pass'] = $new_password;
            }
        }

        if ( empty( $profile_update_message ) ) {

            $update_result = wp_update_user( $user_data );

            if ( is_wp_error( $update_result ) ) {

                $profile_update_message = 'error:' . $update_result->get_error_message();

            } else {

                $profile_update_message = 'success:Student profile updated successfully.';

                // Refresh student data after the update.
                $student = get_userdata( $student_id );
            }
        }
    }
}
    $enrollments_table = $wpdb->prefix . 'apollo_enrollments';

    $enrollments = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
            FROM $enrollments_table
            WHERE user_id = %d
            ORDER BY enrolled_at DESC",
            $student_id
        )
    );

    $back_url = admin_url( 'admin.php?page=apollo-learning' );

    ?>

    <div class="wrap">

        <h1>Apollo Learning – Student Details</h1>

        <p>
            <a href="<?php echo esc_url( $back_url ); ?>">
                &larr; Back to Students
            </a>
        </p>

        <hr>

        <h2>
            <?php echo esc_html( $student->display_name ); ?>
        </h2>

        <table class="widefat striped" style="max-width: 800px;">

            <tbody>

                <tr>
                    <th>Email</th>
                    <td><?php echo esc_html( $student->user_email ); ?></td>
                </tr>

                <tr>
                    <th>Username</th>
                    <td><?php echo esc_html( $student->user_login ); ?></td>
                </tr>

                <tr>
                    <th>Registration Date</th>
                    <td>
                        <?php
                        echo esc_html(
                            date_i18n(
                                get_option( 'date_format' ),
                                strtotime( $student->user_registered )
                            )
                        );
                        ?>
                    </td>
                </tr>

            </tbody>

        </table>
        <!-- STUDENT PROFILE EDIT FORM -->

<h2 style="margin-top:30px;">Edit Student Profile</h2>

<?php if ( ! empty( $profile_update_message ) ) : ?>

    <?php
    $message_parts = explode( ':', $profile_update_message, 2 );
    $message_type  = $message_parts[0];
    $message_text  = $message_parts[1] ?? '';
    ?>

    <div class="notice notice-<?php echo esc_attr( $message_type === 'success' ? 'success' : 'error' ); ?> is-dismissible">
        <p><?php echo esc_html( $message_text ); ?></p>
    </div>

<?php endif; ?>

<form method="post" style="max-width:600px;background:#fff;padding:20px;border:1px solid #ddd;">

    <?php
    wp_nonce_field(
        'apollo_update_student_profile_' . $student_id
    );
    ?>

    <input
        type="hidden"
        name="apollo_update_student_profile"
        value="1"
    >

    <p>
        <label for="student_display_name">
            <strong>Display Name</strong>
        </label>

        <input
            type="text"
            id="student_display_name"
            name="student_display_name"
            value="<?php echo esc_attr( $student->display_name ); ?>"
            class="regular-text"
            required
        >
    </p>

    <p>
        <label for="student_email">
            <strong>Email Address</strong>
        </label>

        <input
            type="email"
            id="student_email"
            name="student_email"
            value="<?php echo esc_attr( $student->user_email ); ?>"
            class="regular-text"
            required
        >
    </p>

    <p>
        <label for="student_password">
            <strong>New Password</strong>
        </label>

        <input
            type="password"
            id="student_password"
            name="student_password"
            class="regular-text"
            minlength="12"
            autocomplete="new-password"
        >

        <br>

        <span class="description">
            Leave blank to keep the current password.
            Minimum 12 characters if changing it.
        </span>
    </p>

    <p>
        <button type="submit" class="button button-primary">
            Update Student Profile
        </button>
    </p>

</form>
        
        <?php
        $available_courses = get_posts( array(
            'post_type'      => 'course',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>

        <?php if ( isset( $_GET['enrollment_message'] ) ) : ?>

            <?php
            $enrollment_message = sanitize_key(
                wp_unslash( $_GET['enrollment_message'] )
            );
            ?>

            <?php if ( 'enrolled' === $enrollment_message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Student enrolled successfully. Previous progress has been preserved.</p>
                </div>
            <?php elseif ( 'revoked' === $enrollment_message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Course access revoked. Previous progress has been preserved.</p>
                </div>
            <?php elseif ( 'error' === $enrollment_message ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>Something went wrong. Please try again.</p>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <h2 style="margin-top: 30px;">Manually Enroll Student</h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

            <input type="hidden" name="action" value="apollo_manage_enrollment">
            <input type="hidden" name="enrollment_action" value="enroll">
            <input type="hidden" name="student_id" value="<?php echo esc_attr( $student_id ); ?>">

            <?php
            wp_nonce_field(
                'apollo_manage_enrollment_' . $student_id
            );
            ?>

            <select name="course_id" required>
                <option value="">Select a course...</option>

                <?php foreach ( $available_courses as $available_course ) : ?>
                    <option value="<?php echo esc_attr( $available_course->ID ); ?>">
                        <?php echo esc_html( $available_course->post_title ); ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <button type="submit" class="button button-primary">
                Enroll Student
            </button>

        </form>

        <h2 style="margin-top: 30px;">Course Enrollments</h2>


        <?php if ( empty( $enrollments ) ) : ?>

            <p>This student has no course enrollments.</p>

        <?php else : ?>

            <table class="widefat striped">

                
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Status</th>
                        <th>Enrollment Date</th>
                        <th>Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>


                <tbody>

                    <?php foreach ( $enrollments as $enrollment ) : ?>

                        <?php
                        $course_id = absint( $enrollment->course_id );
                        $course = get_post( $course_id );

                        $course_title = $course
                            ? get_the_title( $course_id )
                            : 'Course no longer exists';

                        $progress = apollo_get_course_progress(
                            $student_id,
                            $course_id
                        );
                        ?>

                        <tr>

                            <td>
                                <?php
                                if ( $course && get_post_status( $course_id ) === 'publish' ) {
                                    echo '<a href="' . esc_url( get_permalink( $course_id ) ) . '">';
                                    echo esc_html( $course_title );
                                    echo '</a>';
                                } else {
                                    echo esc_html( $course_title );
                                }
                                ?>
                            </td>

                            <td>
                                <?php echo esc_html( ucfirst( $enrollment->status ) ); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    date_i18n(
                                        get_option( 'date_format' ),
                                        strtotime( $enrollment->enrolled_at )
                                    )
                                );
                                ?>
                            </td>

                            <td>

                                <?php
                                echo esc_html( $progress['completed'] );
                                echo ' / ';
                                echo esc_html( $progress['total'] );
                                echo ' lessons (';
                                echo esc_html( $progress['percentage'] );
                                echo '%)';
                                ?>

                                <div
                                    style="background:#ddd;height:8px;max-width:200px;margin-top:6px;border-radius:10px;overflow:hidden;"
                                >
                                    <div
                                        style="height:8px;background:#2271b1;width:<?php echo esc_attr( $progress['percentage'] ); ?>%;"
                                    ></div>
                                </div>

                            
                            </td>

                            <td>

                                <form
                                    method="post"
                                    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="apollo_manage_enrollment"
                                    >

                                    <input
                                        type="hidden"
                                        name="student_id"
                                        value="<?php echo esc_attr( $student_id ); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="course_id"
                                        value="<?php echo esc_attr( $course_id ); ?>"
                                    >

                                    <?php
                                    wp_nonce_field(
                                        'apollo_manage_enrollment_' . $student_id
                                    );
                                    ?>

                                    <?php if ( 'active' === $enrollment->status ) : ?>

                                        <input
                                            type="hidden"
                                            name="enrollment_action"
                                            value="revoke"
                                        >

                                        <button
                                            type="submit"
                                            class="button"
                                            onclick="return confirm('Revoke this student’s access to the course? Their lesson progress will be preserved.');"
                                        >
                                            Revoke Access
                                        </button>

                                    <?php else : ?>

                                        <input
                                            type="hidden"
                                            name="enrollment_action"
                                            value="enroll"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-primary"
                                        >
                                            Restore Access
                                        </button>

                                    <?php endif; ?>

                                </form>

                            </td>

                        </tr>


                        <?php
                        /*
                         * STUDENT LESSON-BY-LESSON REPORT
                         */

                        $course_lessons = apollo_get_course_lessons(
                            $course_id
                        );

                        $lesson_progress_table = $wpdb->prefix . 'apollo_lesson_progress';
                        ?>

                        <tr>
                            <td colspan="5" style="background:#f6f7f7;padding:15px 20px;">

                                <details>

                                    <summary style="cursor:pointer;font-weight:600;">
                                        View Lesson Progress
                                        (<?php echo esc_html( count( $course_lessons->posts ) ); ?> lessons)
                                    </summary>

                                    <?php if ( empty( $course_lessons->posts ) ) : ?>

                                        <p>No lessons are currently available for this course.</p>

                                    <?php else : ?>

                                        <table class="widefat striped" style="margin-top:12px;">

                                            <thead>
                                                <tr>
                                                    <th>Lesson</th>
                                                    <th>Status</th>
                                                    <th>Completion Date</th>
                                                </tr>
                                            </thead>

                                            <tbody>

                                                <?php foreach ( $course_lessons->posts as $lesson ) : ?>

                                                    <?php
                                                    $completed_at = $wpdb->get_var(
                                                        $wpdb->prepare(
                                                            "SELECT completed_at
                                                            FROM $lesson_progress_table
                                                            WHERE user_id = %d
                                                            AND course_id = %d
                                                            AND lesson_id = %d
                                                            LIMIT 1",
                                                            $student_id,
                                                            $course_id,
                                                            $lesson->ID
                                                        )
                                                    );

                                                    $lesson_completed = ! empty( $completed_at );
                                                    ?>

                                                    <tr>

                                                        <td>
                                                            <?php echo esc_html( get_the_title( $lesson->ID ) ); ?>
                                                        </td>

                                                        <td>
                                                            <?php if ( $lesson_completed ) : ?>

                                                                <span style="color:#16803c;font-weight:600;">
                                                                    &#10003; Completed
                                                                </span>

                                                            <?php else : ?>

                                                                <span style="color:#b7791f;font-weight:600;">
                                                                    Pending
                                                                </span>

                                                            <?php endif; ?>
                                                        </td>

                                                        <td>
                                                            <?php
                                                            if ( $lesson_completed ) {

                                                                echo esc_html(
                                                                    date_i18n(
                                                                        get_option( 'date_format' ),
                                                                        strtotime( $completed_at )
                                                                    )
                                                                );

                                                            } else {

                                                                echo '&mdash;';
                                                            }
                                                            ?>
                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                            </tbody>

                                        </table>

                                    <?php endif; ?>

                                </details>

                            </td>
                        </tr>


                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

    <?php
}



/* =========================================================
   14.1. ADMIN ENROLLMENT MANAGEMENT
========================================================= */

function apollo_admin_manage_enrollment() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to manage enrollments.' );
    }

    $student_id = isset( $_POST['student_id'] )
        ? absint( wp_unslash( $_POST['student_id'] ) )
        : 0;

    $course_id = isset( $_POST['course_id'] )
        ? absint( wp_unslash( $_POST['course_id'] ) )
        : 0;

    $enrollment_action = isset( $_POST['enrollment_action'] )
        ? sanitize_key( wp_unslash( $_POST['enrollment_action'] ) )
        : '';

    if (
        ! $student_id ||
        ! $course_id ||
        ! get_userdata( $student_id ) ||
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        wp_die( 'Invalid student or course.' );
    }

    if ( ! in_array( $enrollment_action, array( 'enroll', 'revoke' ), true ) ) {
        wp_die( 'Invalid enrollment action.' );
    }

    check_admin_referer(
        'apollo_manage_enrollment_' . $student_id
    );

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_enrollments';
    $now = current_time( 'mysql' );

    if ( 'enroll' === $enrollment_action ) {

        // Create an enrollment or reactivate the existing one.
        // Existing order ID and original enrollment date are preserved.
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table
                    (user_id, course_id, order_id, status, enrolled_at, updated_at)
                VALUES
                    (%d, %d, 0, 'active', %s, %s)
                ON DUPLICATE KEY UPDATE
                    status = 'active',
                    updated_at = VALUES(updated_at)",
                $student_id,
                $course_id,
                $now,
                $now
            )
        );

        $message = false !== $result ? 'enrolled' : 'error';

    } else {

        // Revoke access without deleting progress or enrollment history.
        $result = $wpdb->update(
            $table,
            array(
                'status'     => 'inactive',
                'updated_at' => $now,
            ),
            array(
                'user_id'   => $student_id,
                'course_id' => $course_id,
            ),
            array( '%s', '%s' ),
            array( '%d', '%d' )
        );

        $message = false !== $result ? 'revoked' : 'error';
    }

    $redirect_url = add_query_arg(
        array(
            'page'       => 'apollo-student-details',
            'student_id' => $student_id,
            'enrollment_message' => $message,
        ),
        admin_url( 'admin.php' )
    );

    wp_safe_redirect( $redirect_url );
    exit;
}

add_action(
    'admin_post_apollo_manage_enrollment',
    'apollo_admin_manage_enrollment'
);



/* =========================================================
   15. APOLLO COURSE LEARNING HUB
========================================================= */

/**
 * Get the first unfinished lesson available to a student.
 */

/**
 * Get the next unfinished lesson available to a student.
 * Returns 0 when all accessible lessons are completed.
 */
function apollo_get_next_available_lesson( $course_id, $user_id ) {

    $lessons = apollo_get_course_lessons( $course_id );

    if ( ! $lessons->have_posts() ) {
        return 0;
    }

    $has_access = apollo_user_has_course_access(
        $user_id,
        $course_id
    );

    foreach ( $lessons->posts as $lesson ) {

        $lesson_id = absint( $lesson->ID );

        $is_preview = apollo_is_free_preview_lesson( $lesson_id );

        if ( ! $has_access && ! $is_preview ) {
            continue;
        }

        if (
            ! $user_id ||
            ! apollo_is_lesson_completed( $user_id, $lesson_id )
        ) {
            return $lesson_id;
        }
    }

    return 0;
}



/**
 * Handle completion from the course learning hub.
 */

/**
 * Complete a lesson and automatically open the next unfinished lesson.
 */
function apollo_handle_hub_lesson_completion() {

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to complete a lesson.' );
    }

    $lesson_id = isset( $_POST['lesson_id'] )
        ? absint( wp_unslash( $_POST['lesson_id'] ) )
        : 0;

    $course_id = isset( $_POST['course_id'] )
        ? absint( wp_unslash( $_POST['course_id'] ) )
        : 0;

    if (
        ! $lesson_id ||
        ! $course_id ||
        get_post_type( $lesson_id ) !== 'lesson' ||
        get_post_type( $course_id ) !== 'course'
    ) {
        wp_die( 'Invalid course or lesson.' );
    }

    check_admin_referer(
        'apollo_hub_complete_' . $lesson_id
    );

    $lesson_course = absint(
        get_field( 'lesson_course', $lesson_id )
    );

    if ( $lesson_course !== $course_id ) {
        wp_die( 'This lesson does not belong to this course.' );
    }

    $user_id = get_current_user_id();

    if ( ! apollo_user_has_course_access( $user_id, $course_id ) ) {
        wp_die( 'You do not have access to this course.' );
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'apollo_lesson_progress';

    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO $table_name
                (user_id, course_id, lesson_id, completed_at)
            VALUES
                (%d, %d, %d, %s)",
            $user_id,
            $course_id,
            $lesson_id,
            current_time( 'mysql' )
        )
    );

    if ( false === $result ) {
        wp_die( 'There was a problem saving your lesson progress. Please try again.' );
    }

    // Find the next unfinished lesson.
    $next_lesson_id = apollo_get_next_available_lesson(
        $course_id,
        $user_id
    );

    if ( $next_lesson_id ) {

        // Open the next lesson automatically.
        $redirect_url = add_query_arg(
            'apollo_lesson',
            $next_lesson_id,
            get_permalink( $course_id )
        );

    } else {

        // All accessible lessons are complete.
        $redirect_url = add_query_arg(
            'apollo_course_completed',
            '1',
            get_permalink( $course_id )
        );
    }

    wp_safe_redirect( $redirect_url );
    exit;
}


add_action(
    'admin_post_apollo_hub_complete_lesson',
    'apollo_handle_hub_lesson_completion'
);


/**
 * Course Learning Hub shortcode.
 *
 * Usage:
 * [apollo_course_learning_hub]
 *
 * Optional:
 * [apollo_course_learning_hub course_id="123"]
 */
function apollo_course_learning_hub_shortcode( $atts ) {

    if ( ! function_exists( 'get_field' ) ) {
        return '<p>Advanced Custom Fields is required for the learning hub.</p>';
    }

    $atts = shortcode_atts(
        array(
            'course_id' => 0,
        ),
        $atts,
        'apollo_course_learning_hub'
    );

    $course_id = absint( $atts['course_id'] );

    if ( ! $course_id ) {
        $course_id = get_queried_object_id();
    }

    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course'
    ) {
        return '<p>Course not found. Please check the course page or shortcode course_id.</p>';
    }

    $user_id = get_current_user_id();

    $has_access = $user_id
        ? apollo_user_has_course_access( $user_id, $course_id )
        : false;

    $lessons_query = apollo_get_course_lessons( $course_id );
    $lessons = $lessons_query->posts;

    if ( empty( $lessons ) ) {
        return '<div class="apollo-learning-hub"><h2>' .
            esc_html( get_the_title( $course_id ) ) .
            '</h2><p>No lessons are available yet.</p></div>';
    }

    /*
     * Select the lesson from the URL if requested.
     */
    $requested_lesson = isset( $_GET['apollo_lesson'] )
        ? absint( wp_unslash( $_GET['apollo_lesson'] ) )
        : 0;

    $current_lesson_id = 0;

    if ( $requested_lesson ) {

        foreach ( $lessons as $lesson ) {

            if ( absint( $lesson->ID ) === $requested_lesson ) {

                $is_preview = apollo_is_free_preview_lesson( $requested_lesson );

                if ( $has_access || $is_preview ) {
                    $current_lesson_id = $requested_lesson;
                }

                break;
            }
        }
    }

    /*
     * If no valid lesson was selected, choose the next available one.
     */
    if ( ! $current_lesson_id ) {
        $current_lesson_id = apollo_get_next_available_lesson(
            $course_id,
            $user_id
        );
    }

    
    
    if ( ! $current_lesson_id ) {

        if ( $has_access ) {

            // Display the certificate when specifically requested.
            $show_certificate = isset( $_GET['apollo_certificate'] )
                && '1' === sanitize_text_field(
                    wp_unslash( $_GET['apollo_certificate'] )
                );

            if ( $show_certificate ) {
                return apollo_render_course_certificate(
                    $user_id,
                    $course_id
                );
            }

            $final_progress = apollo_get_course_progress(
                $user_id,
                $course_id
            );

            $certificate_url = add_query_arg(
                'apollo_certificate',
                '1',
                get_permalink( $course_id )
            );

            return '<div class="apollo-course-completed">
                <h2>Congratulations!</h2>

                <p>You have completed all the lessons in ' .
                esc_html( get_the_title( $course_id ) ) .
                '.</p>

                <p>Your final progress: ' .
                esc_html( $final_progress['percentage'] ) .
                '%</p>

                <a class="apollo-get-certificate" href="' .
                esc_url( $certificate_url ) .
                '">Get Your Certificate</a>

                <a class="apollo-review-course" href="' .
                esc_url( get_permalink( $course_id ) ) .
                '">Review Course</a>

            </div>';
        }

        return '<p>No lessons are currently available.</p>';
    }



    $current_lesson = get_post( $current_lesson_id );

    if ( ! $current_lesson ) {
        return '<p>The selected lesson could not be found.</p>';
    }

    $current_video = get_field(
        'lesson_video_url',
        $current_lesson_id
    );

    $current_resources = get_field(
        'lesson_resources',
        $current_lesson_id
    );

    $current_order = get_field(
        'lesson_order',
        $current_lesson_id
    );

    $current_is_preview = apollo_is_free_preview_lesson(
        $current_lesson_id
    );

    $progress = $user_id
        ? apollo_get_course_progress( $user_id, $course_id )
        : array(
            'total'      => count( $lessons ),
            'completed'  => 0,
            'percentage' => 0,
        );

    $course_title = get_the_title( $course_id );

    $course_description = get_post_field(
        'post_excerpt',
        $course_id
    );

    if ( empty( $course_description ) ) {
        $course_description = get_post_field(
            'post_content',
            $course_id
        );
    }

    $instructor = get_field( 'course_instructor', $course_id );
    $duration = get_field( 'course_duration', $course_id );
    $level = get_field( 'course_level', $course_id );

    /*
     * Find previous and next lessons.
     */
    $lesson_ids = array_map(
        'absint',
        wp_list_pluck( $lessons, 'ID' )
    );

    $current_index = array_search(
        $current_lesson_id,
        $lesson_ids,
        true
    );

    $previous_lesson_id = (
        $current_index !== false && $current_index > 0
    )
        ? $lesson_ids[ $current_index - 1 ]
        : 0;

    $next_lesson_id = (
        $current_index !== false &&
        isset( $lesson_ids[ $current_index + 1 ] )
    )
        ? $lesson_ids[ $current_index + 1 ]
        : 0;

    ob_start();

    ?>

    <div class="apollo-learning-hub">

        <!-- COURSE HEADER -->

        <header class="apollo-hub-header">

            <div class="apollo-hub-course-heading">

                <span class="apollo-hub-eyebrow">
                    APOLLO PHOTOGRAPHY ACADEMY
                </span>

                <h1><?php echo esc_html( $course_title ); ?></h1>

                <?php if ( ! empty( $course_description ) ) : ?>

                    <div class="apollo-hub-description">
                        <?php echo wp_kses_post( wpautop( $course_description ) ); ?>
                    </div>

                <?php endif; ?>

                <div class="apollo-hub-course-meta">

                    <?php if ( $instructor ) : ?>
                        <span>
                            Instructor: <?php echo esc_html( $instructor ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $duration ) : ?>
                        <span>
                            Duration: <?php echo esc_html( $duration ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $level ) : ?>
                        <span>
                            Level: <?php echo esc_html( $level ); ?>
                        </span>
                    <?php endif; ?>

                </div>

            </div>

            <?php if ( has_post_thumbnail( $course_id ) ) : ?>

                <div class="apollo-hub-course-image">
                    <?php
                    echo get_the_post_thumbnail(
                        $course_id,
                        'large',
                        array(
                            'alt' => esc_attr( $course_title ),
                        )
                    );
                    ?>
                </div>

            <?php endif; ?>

        </header>


        <!-- LEARNING PROGRESS -->

        <section class="apollo-hub-progress">

            <div class="apollo-hub-progress-heading">

                <strong>Your Learning Progress</strong>

                <span>
                    <?php echo esc_html( $progress['percentage'] ); ?>%
                </span>

            </div>

            <div
                class="apollo-hub-progress-track"
                role="progressbar"
                aria-valuenow="<?php echo esc_attr( $progress['percentage'] ); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="apollo-hub-progress-fill"
                    style="width: <?php echo esc_attr( $progress['percentage'] ); ?>%;"
                ></div>
            </div>

            <p>
                <?php echo esc_html( $progress['completed'] ); ?>
                of
                <?php echo esc_html( $progress['total'] ); ?>
                lessons completed
            </p>

        </section>


        <!-- VIDEO AND LESSON SIDEBAR -->

        <div class="apollo-hub-layout">

            <main class="apollo-hub-main">

                <div class="apollo-hub-current-lesson">

                    <div class="apollo-hub-lesson-heading">

                        <span class="apollo-hub-lesson-number">
                            Lesson <?php echo esc_html( $current_order ); ?>
                        </span>

                        <?php if ( $current_is_preview ) : ?>
                            <span class="apollo-hub-preview-label">
                                Free Preview
                            </span>
                        <?php endif; ?>

                        <h2>
                            <?php echo esc_html( get_the_title( $current_lesson_id ) ); ?>
                        </h2>

                    </div>


                    <!-- VIDEO PLAYER -->

                    <?php if ( ! empty( $current_video ) ) : ?>

                        <div class="apollo-hub-video">

                            <?php
                            $video_embed = wp_oembed_get(
                                esc_url( $current_video )
                            );

                            if ( $video_embed ) {
                                echo $video_embed;
                            } else {
                                ?>

                                <a
                                    href="<?php echo esc_url( $current_video ); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Watch Lesson Video
                                </a>

                                <?php
                            }
                            ?>

                        </div>

                    <?php else : ?>

                        <div class="apollo-hub-no-video">
                            <p>A video has not been added to this lesson yet.</p>
                        </div>

                    <?php endif; ?>


                    <!-- LESSON CONTENT -->

                    <div class="apollo-hub-lesson-content">

                        <?php
                        echo apply_filters(
                            'the_content',
                            $current_lesson->post_content
                        );
                        ?>

                    </div>


                    <!-- LESSON RESOURCES -->

                    <?php if ( ! empty( $current_resources ) ) : ?>

                        <?php
                        $attachment_id = absint( $current_resources );

                        if ( $attachment_id ) :

                            $resource_name = get_the_title( $attachment_id );

                            $download_url = apollo_get_lesson_resource_download_url(
                                $current_lesson_id
                            );
                        ?>

                            <div class="apollo-hub-resources">
                                <h3>Lesson Resources</h3>

                                <a href="<?php echo esc_url( $download_url ); ?>">
                                    <?php echo esc_html( $resource_name ); ?>
                                </a>
                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                    <!-- LESSON COMPLETION -->

                    <?php
                    $is_completed = $user_id
                        ? apollo_is_lesson_completed( $user_id, $current_lesson_id )
                        : false;
                    ?>

                    <div class="apollo-hub-completion">

                        <?php if ( $is_completed ) : ?>

                            <p class="apollo-hub-completed-message">
                                You have completed this lesson.
                            </p>

                        <?php elseif ( $has_access ) : ?>

                            <form
                                method="post"
                                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="apollo_hub_complete_lesson"
                                >

                                <input
                                    type="hidden"
                                    name="lesson_id"
                                    value="<?php echo esc_attr( $current_lesson_id ); ?>"
                                >

                                <input
                                    type="hidden"
                                    name="course_id"
                                    value="<?php echo esc_attr( $course_id ); ?>"
                                >

                                <?php
                                wp_nonce_field(
                                    'apollo_hub_complete_' . $current_lesson_id
                                );
                                ?>

                                <button
                                    type="submit"
                                    class="apollo-hub-complete-button"
                                >
                                    Mark as Complete
                                </button>

                            </form>

                        <?php else : ?>

                            <p>
                                Enroll in this course to track your lesson progress.
                            </p>

                        <?php endif; ?>

                    </div>


                    <!-- PREVIOUS AND NEXT -->

                    <nav class="apollo-hub-navigation">

                        <?php if ( $previous_lesson_id ) : ?>

                            <?php
                            $previous_allowed = $has_access ||
                                apollo_is_free_preview_lesson( $previous_lesson_id );
                            ?>

                            <?php if ( $previous_allowed ) : ?>

                                <a href="<?php
                                    echo esc_url(
                                        add_query_arg(
                                            'apollo_lesson',
                                            $previous_lesson_id,
                                            get_permalink( $course_id )
                                        )
                                    );
                                ?>">
                                    &larr; Previous Lesson
                                </a>

                            <?php endif; ?>

                        <?php endif; ?>


                        <?php if ( $next_lesson_id ) : ?>

                            <?php
                            $next_allowed = $has_access ||
                                apollo_is_free_preview_lesson( $next_lesson_id );
                            ?>

                            <?php if ( $next_allowed ) : ?>

                                <a href="<?php
                                    echo esc_url(
                                        add_query_arg(
                                            'apollo_lesson',
                                            $next_lesson_id,
                                            get_permalink( $course_id )
                                        )
                                    );
                                ?>">
                                    Next Lesson &rarr;
                                </a>

                            <?php endif; ?>

                        <?php endif; ?>

                    </nav>

                </div>

            </main>


            <!-- LESSON SIDEBAR -->

            <aside class="apollo-hub-sidebar">

                <div class="apollo-hub-sidebar-heading">

                    <h2>Course Lessons</h2>

                    <span>
                        <?php echo esc_html( count( $lessons ) ); ?> lessons
                    </span>

                </div>


                <div class="apollo-hub-lesson-list">

                    <?php foreach ( $lessons as $lesson ) : ?>

                        <?php
                        $lesson_id = absint( $lesson->ID );

                        $lesson_order = get_field(
                            'lesson_order',
                            $lesson_id
                        );

                        $lesson_preview = apollo_is_free_preview_lesson(
                            $lesson_id
                        );

                        $lesson_completed = $user_id
                            ? apollo_is_lesson_completed( $user_id, $lesson_id )
                            : false;

                        $lesson_unlocked = $has_access || $lesson_preview;

                        $lesson_current = (
                            $lesson_id === $current_lesson_id
                        );
                        ?>

                        <div class="apollo-hub-lesson-item
                            <?php echo $lesson_current ? 'is-current' : ''; ?>
                            <?php echo $lesson_completed ? 'is-completed' : ''; ?>
                            <?php echo ! $lesson_unlocked ? 'is-locked' : ''; ?>">

                            <span class="apollo-hub-sidebar-number">

                                <?php
                                if ( $lesson_completed ) {
                                    echo '&#10003;';
                                } elseif ( ! $lesson_unlocked ) {
                                    echo '&#128274;';
                                } else {
                                    echo esc_html( $lesson_order );
                                }
                                ?>

                            </span>

                            <div class="apollo-hub-sidebar-details">

                                <?php if ( $lesson_unlocked ) : ?>

                                    <a href="<?php
                                        echo esc_url(
                                            add_query_arg(
                                                'apollo_lesson',
                                                $lesson_id,
                                                get_permalink( $course_id )
                                            )
                                        );
                                    ?>">
                                        <?php echo esc_html( get_the_title( $lesson_id ) ); ?>
                                    </a>

                                <?php else : ?>

                                    <span>
                                        <?php echo esc_html( get_the_title( $lesson_id ) ); ?>
                                    </span>

                                <?php endif; ?>


                                <?php if ( $lesson_preview ) : ?>

                                    <small>Free Preview</small>

                                <?php elseif ( $lesson_completed ) : ?>

                                    <small>Completed</small>

                                <?php elseif ( ! $lesson_unlocked ) : ?>

                                    <small>Locked</small>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </aside>

        </div>

    </div>

    <?php

    wp_reset_postdata();

    return ob_get_clean();
}

add_shortcode(
    'apollo_course_learning_hub',
    'apollo_course_learning_hub_shortcode'
);


/* =========================================================
   16. APOLLO COURSE COMPLETION CERTIFICATES
========================================================= */

/**
 * Verify that a student has completed every lesson in a course.
 */
function apollo_user_completed_course( $user_id, $course_id ) {

    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );

    if (
        ! $user_id ||
        ! $course_id ||
        get_post_type( $course_id ) !== 'course' ||
        ! apollo_user_has_course_access( $user_id, $course_id )
    ) {
        return false;
    }

    $progress = apollo_get_course_progress(
        $user_id,
        $course_id
    );

    return (
        $progress['total'] > 0 &&
        $progress['completed'] >= $progress['total']
    );
}

/**
 * Get or create a permanent certificate ID.
 */

function apollo_get_certificate_record( $user_id, $course_id ) {

    global $wpdb;

    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );

    if ( ! apollo_user_completed_course( $user_id, $course_id ) ) {
        return false;
    }

    $table = $wpdb->prefix . 'apollo_certificates';

    // Return the existing certificate if already issued.
    $record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
            FROM $table
            WHERE user_id = %d
            AND course_id = %d
            LIMIT 1",
            $user_id,
            $course_id
        )
    );

    if ( $record ) {
        return $record;
    }

    // Generate a unique certificate code.
    $certificate_code = '';

    for ( $attempt = 0; $attempt < 5; $attempt++ ) {

        $certificate_code = 'APOLLO-' . strtoupper(
            wp_generate_password( 12, false, false )
        );

        $inserted = $wpdb->insert(
            $table,
            array(
                'user_id'          => $user_id,
                'course_id'        => $course_id,
                'certificate_code' => $certificate_code,
                'issued_at'        => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s' )
        );

        if ( false !== $inserted ) {
            break;
        }

        // Check whether another request created the certificate.
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM $table
                WHERE user_id = %d
                AND course_id = %d
                LIMIT 1",
                $user_id,
                $course_id
            )
        );

        if ( $record ) {
            return $record;
        }
    }

    // Retrieve the newly created certificate.
    $record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
            FROM $table
            WHERE user_id = %d
            AND course_id = %d
            LIMIT 1",
            $user_id,
            $course_id
        )
    );

    if ( ! $record ) {
        return false;
    }

    // Notify the student only when a new certificate was issued.
    apollo_add_student_notification(
        $user_id,
        'certificate_earned',
        'Certificate Earned!',
        sprintf(
            'Your certificate for %s has been issued. You can now view and download it.',
            get_the_title( $course_id )
        ),
        $course_id
    );

    return $record;
}


/**
 * Display a personalized certificate.
 */
function apollo_render_course_certificate( $user_id, $course_id ) {

    if ( ! apollo_user_completed_course( $user_id, $course_id ) ) {
        return '<div class="apollo-certificate-error">
            <h2>Certificate unavailable</h2>
            <p>You must complete all lessons in this course before receiving your certificate.</p>
        </div>';
    }

    $student = get_userdata( $user_id );

    if ( ! $student ) {
        return '<p>Student account not found.</p>';
    }

        $certificate_record = apollo_get_certificate_record(
        $user_id,
        $course_id
    );

    if ( ! $certificate_record ) {
        return '<p>Unable to issue this certificate. Please try again.</p>';
    }

    $certificate_code = $certificate_record->certificate_code;

    $verification_url = add_query_arg(
        'certificate_id',
        rawurlencode( $certificate_code ),
        home_url( '/verify-certificate/' )
    );

    global $wpdb;

    $progress_table = $wpdb->prefix . 'apollo_lesson_progress';

    $last_completion = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(completed_at)
            FROM $progress_table
            WHERE user_id = %d
            AND course_id = %d",
            $user_id,
            $course_id
        )
    );

    $completion_timestamp = $last_completion
        ? strtotime( $last_completion )
        : current_time( 'timestamp' );

    $completion_date = date_i18n(
        get_option( 'date_format' ),
        $completion_timestamp
    );

    $student_name = $student->display_name;
    $course_title = get_the_title( $course_id );

    ob_start();
    ?>

    <div class="apollo-certificate-page">

        <div class="apollo-certificate">

            <div class="apollo-certificate-inner">

                <div class="apollo-certificate-brand">
                    APOLLO
                    <span>PHOTOGRAPHY ACADEMY</span>
                </div>

                <div class="apollo-certificate-decoration">
                    ✦
                </div>

                <p class="apollo-certificate-eyebrow">
                    CERTIFICATE OF COMPLETION
                </p>

                <h1>Congratulations!</h1>

                <p class="apollo-certificate-intro">
                    This certificate is proudly presented to
                </p>

                <h2 class="apollo-certificate-student">
                    <?php echo esc_html( $student_name ); ?>
                </h2>

                <div class="apollo-certificate-divider"></div>

                <p class="apollo-certificate-description">
                    For successfully completing the photography course
                </p>

                <h3 class="apollo-certificate-course">
                    <?php echo esc_html( $course_title ); ?>
                </h3>

                <p class="apollo-certificate-description">
                    demonstrating dedication to learning and developing
                    photographic skills.
                </p>

                <div class="apollo-certificate-footer">

                    <div class="apollo-certificate-date">
                        <strong>
                            <?php echo esc_html( $completion_date ); ?>
                        </strong>
                        <span>DATE OF COMPLETION</span>
                    </div>

                    <div class="apollo-certificate-signature">
                        <strong>Apollo Photography Academy</strong>
                        <span>ACADEMY</span>
                    </div>

                </div>

                                <div class="apollo-certificate-id">
                    CERTIFICATE ID:
                    <?php echo esc_html( $certificate_code ); ?>

                    <br>

                    VERIFY:
                    <a href="<?php echo esc_url( $verification_url ); ?>">
                        Verify Certificate
                    </a>
                </div>

            </div>

        </div>

        <div class="apollo-certificate-actions">

            <button
                type="button"
                class="apollo-certificate-print-button"
                onclick="window.print();"
            >
                Print / Save as PDF
            </button>

            <a
                class="apollo-certificate-back-button"
                href="<?php echo esc_url( remove_query_arg( 'apollo_certificate' ) ); ?>"
            >
                Back to Course
            </a>

        </div>

    </div>

    <style>
        .apollo-certificate-page {
            max-width: 1100px;
            margin: 40px auto;
            padding: 20px;
            color: #28251f;
        }

        .apollo-certificate {
            background: #f8f5ed;
            border: 2px solid #a97850;
            padding: 14px;
            text-align: center;
        }

        .apollo-certificate-inner {
            position: relative;
            border: 1px solid #c8a77d;
            padding: 55px 35px 35px;
            min-height: 570px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .apollo-certificate-brand {
            color: #29251f;
            font-family: Georgia, serif;
            font-size: 30px;
            font-weight: bold;
            letter-spacing: 7px;
        }

        .apollo-certificate-brand span {
            display: block;
            margin-top: 8px;
            font-family: Arial, sans-serif;
            font-size: 10px;
            letter-spacing: 4px;
        }

        .apollo-certificate-decoration {
            color: #a97850;
            font-size: 27px;
            margin: 25px 0 10px;
        }

        .apollo-certificate-eyebrow {
            color: #a97850;
            font-size: 13px;
            letter-spacing: 5px;
            font-weight: bold;
        }

        .apollo-certificate h1 {
            color: #29251f;
            font-family: Georgia, serif;
            font-size: clamp(32px, 5vw, 48px);
            margin: 12px 0 20px;
        }

        .apollo-certificate-intro,
        .apollo-certificate-description {
            color: #696258;
            font-size: 15px;
            line-height: 1.8;
        }

        .apollo-certificate-student {
            color: #a97850;
            font-family: Georgia, serif;
            font-size: clamp(27px, 4vw, 40px);
            font-weight: normal;
            margin: 15px 0;
        }

        .apollo-certificate-divider {
            width: 180px;
            height: 1px;
            background: #c8a77d;
            margin: 10px auto 20px;
        }

        .apollo-certificate-course {
            color: #29251f;
            font-family: Georgia, serif;
            font-size: clamp(21px, 3vw, 29px);
            margin: 15px 0;
        }

        .apollo-certificate-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 40px;
            width: 100%;
            max-width: 650px;
            margin: 55px auto 0;
        }

        .apollo-certificate-date,
        .apollo-certificate-signature {
            display: flex;
            flex-direction: column;
            gap: 9px;
            min-width: 180px;
        }

        .apollo-certificate-date strong,
        .apollo-certificate-signature strong {
            border-bottom: 1px solid #a97850;
            padding-bottom: 9px;
            font-family: Georgia, serif;
            font-size: 15px;
        }

        .apollo-certificate-date span,
        .apollo-certificate-signature span {
            color: #82796d;
            font-size: 9px;
            letter-spacing: 2px;
        }

        .apollo-certificate-id {
            position: absolute;
            bottom: 15px;
            right: 20px;
            color: #9a9082;
            font-size: 9px;
            letter-spacing: 1px;
        }

        .apollo-certificate-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 25px;
        }

        .apollo-certificate-print-button,
        .apollo-certificate-back-button {
            display: inline-block;
            padding: 13px 24px;
            border: none;
            background: #a97850;
            color: #fff !important;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        .apollo-certificate-back-button {
            background: #29251f;
        }

        .apollo-certificate-error {
            padding: 30px;
            background: #f8f5ed;
        }

        @media print {

            @page {
                size: landscape;
                margin: 10mm;
            }

            body * {
                visibility: hidden !important;
            }

            .apollo-certificate-page,
            .apollo-certificate-page * {
                visibility: visible !important;
            }

            .apollo-certificate-page {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .apollo-certificate {
                border: 2px solid #a97850 !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .apollo-certificate-actions {
                display: none !important;
            }

            .apollo-certificate-inner {
                min-height: 145mm;
            }
        }

        @media screen and (max-width: 600px) {

            .apollo-certificate-inner {
                padding: 35px 15px 50px;
            }

            .apollo-certificate-footer {
                flex-direction: column;
                align-items: center;
                gap: 25px;
                margin-top: 35px;
            }

            .apollo-certificate-id {
                font-size: 8px;
            }
        }
    </style>

    <?php

    return ob_get_clean();
}


/* =========================================================
   17. PUBLIC CERTIFICATE VERIFICATION
========================================================= */

function apollo_verify_certificate_shortcode() {

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_certificates';

    $certificate_code = isset( $_POST['certificate_id'] )
        ? strtoupper(
            sanitize_text_field(
                wp_unslash( $_POST['certificate_id'] )
            )
        )
        : '';

    // Allow verification links from certificates.
    if ( empty( $certificate_code ) && isset( $_GET['certificate_id'] ) ) {
        $certificate_code = strtoupper(
            sanitize_text_field(
                wp_unslash( $_GET['certificate_id'] )
            )
        );
    }

    ob_start();

    ?>

    <div class="apollo-certificate-verification">

        <h2>Verify an Apollo Certificate</h2>

        <p>
            Enter the certificate ID to check whether an Apollo
            Photography Academy certificate is genuine.
        </p>

        <form method="get">

            <label for="apollo-certificate-id">
                Certificate ID
            </label>

            <input
                type="text"
                id="apollo-certificate-id"
                name="certificate_id"
                value="<?php echo esc_attr( $certificate_code ); ?>"
                placeholder="Example: APOLLO-ABC123XYZ789"
                required
            >

            <button type="submit">
                Verify Certificate
            </button>

        </form>

        <?php if ( $certificate_code !== '' ) : ?>

            <?php
            $certificate = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT *
                    FROM $table
                    WHERE certificate_code = %s
                    LIMIT 1",
                    $certificate_code
                )
            );
            ?>

            <?php if ( $certificate ) : ?>

                <?php
                $student = get_userdata( $certificate->user_id );
                $course_exists = (
                    get_post_type( $certificate->course_id ) === 'course'
                );
                ?>

                <?php if ( $student && $course_exists ) : ?>

                    <div class="apollo-verification-valid">

                        <h3>Certificate Verified</h3>

                        <p>
                            <strong>Status:</strong> Valid
                        </p>

                        <p>
                            <strong>Certificate ID:</strong>
                            <?php echo esc_html( $certificate->certificate_code ); ?>
                        </p>

                        <p>
                            <strong>Student:</strong>
                            <?php echo esc_html( $student->display_name ); ?>
                        </p>

                        <p>
                            <strong>Course:</strong>
                            <?php echo esc_html( get_the_title( $certificate->course_id ) ); ?>
                        </p>

                        <p>
                            <strong>Date Issued:</strong>
                            <?php
                            echo esc_html(
                                date_i18n(
                                    get_option( 'date_format' ),
                                    strtotime( $certificate->issued_at )
                                )
                            );
                            ?>
                        </p>

                    </div>

                <?php else : ?>

                    <div class="apollo-verification-invalid">
                        <h3>Certificate Could Not Be Verified</h3>
                        <p>The certificate record is incomplete.</p>
                    </div>

                <?php endif; ?>

            <?php else : ?>

                <div class="apollo-verification-invalid">

                    <h3>Certificate Not Found</h3>

                    <p>
                        We could not find a certificate with that ID.
                        Please check the ID and try again.
                    </p>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>

    <style>
        .apollo-certificate-verification {
            max-width: 700px;
            margin: 40px auto;
            padding: 30px;
            background: #f8f5ed;
            color: #29251f;
        }

        .apollo-certificate-verification form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 25px 0;
        }

        .apollo-certificate-verification input {
            width: 100%;
            padding: 13px;
            border: 1px solid #c8a77d;
            box-sizing: border-box;
        }

        .apollo-certificate-verification button {
            padding: 13px 22px;
            border: none;
            background: #a97850;
            color: white;
            cursor: pointer;
        }

        .apollo-verification-valid,
        .apollo-verification-invalid {
            margin-top: 25px;
            padding: 22px;
            border: 1px solid #c8a77d;
            background: #fff;
        }

        .apollo-verification-valid h3 {
            color: #287443;
        }

        .apollo-verification-invalid h3 {
            color: #a12d2d;
        }
    </style>

    <?php

    return ob_get_clean();
}

add_shortcode(
    'apollo_verify_certificate',
    'apollo_verify_certificate_shortcode'
);

/* =========================================================
   17. ADMIN CERTIFICATE MANAGEMENT
========================================================= */

function apollo_admin_certificates_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    global $wpdb;

    /*
     * If an administrator selects View Certificate,
     * display the certificate for the selected student.
     */

    $certificate_student_id = isset( $_GET['certificate_student'] )
        ? absint( $_GET['certificate_student'] )
        : 0;

    $certificate_course_id = isset( $_GET['certificate_course'] )
        ? absint( $_GET['certificate_course'] )
        : 0;

    if ( $certificate_student_id && $certificate_course_id ) {

        $student = get_userdata( $certificate_student_id );

        echo '<div class="wrap">';
        echo '<h1>Apollo Learning – Student Certificate</h1>';

        echo '<p><a href="' .
            esc_url( admin_url( 'admin.php?page=apollo-learning-certificates' ) ) .
            '">&larr; Back to Certificates</a></p>';

        if ( ! $student ) {

            echo '<div class="notice notice-error"><p>Student not found.</p></div>';

        } elseif ( ! apollo_user_completed_course(
            $certificate_student_id,
            $certificate_course_id
        ) ) {

            echo '<div class="notice notice-error"><p>This student is not eligible for this certificate.</p></div>';

        } else {

            echo apollo_render_course_certificate(
                $certificate_student_id,
                $certificate_course_id
            );
        }

        echo '</div>';

        return;
    }

    /*
     * Retrieve active enrollments.
     */

    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';

    $enrollments = $wpdb->get_results(
        "SELECT user_id, course_id
        FROM $enrollment_table
        WHERE status = 'active'
        ORDER BY enrolled_at DESC"
    );

    $certificates = array();

    /*
     * Keep only students who have completed every lesson.
     */

    foreach ( $enrollments as $enrollment ) {

        $user_id = absint( $enrollment->user_id );
        $course_id = absint( $enrollment->course_id );

        if (
            get_post_type( $course_id ) !== 'course' ||
            get_post_status( $course_id ) !== 'publish'
        ) {
            continue;
        }

        if ( ! apollo_user_completed_course( $user_id, $course_id ) ) {
            continue;
        }

        $student = get_userdata( $user_id );

        if ( ! $student ) {
            continue;
        }

        $completion_date = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(completed_at)
                FROM {$wpdb->prefix}apollo_lesson_progress
                WHERE user_id = %d
                AND course_id = %d",
                $user_id,
                $course_id
            )
        );

        $certificates[] = array(
            'user_id'         => $user_id,
            'student_name'    => $student->display_name,
            'student_email'   => $student->user_email,
            'course_id'       => $course_id,
            'course_title'    => get_the_title( $course_id ),
            'completion_date' => $completion_date,
        );
    }

    ?>

    <div class="wrap">

        <h1>Apollo Learning – Certificates</h1>

        <p>
            View certificates earned by students who have completed
            all lessons in their enrolled courses.
        </p>

        <hr>

        <p>
            <strong>Total certificates earned:</strong>
            <?php echo esc_html( count( $certificates ) ); ?>
        </p>

        <table class="widefat fixed striped">

            <thead>
                <tr>
                    <th>Student</th>
                    <th>Email</th>
                    <th>Completed Course</th>
                    <th>Completion Date</th>
                    <th>Certificate</th>
                </tr>
            </thead>

            <tbody>

                <?php if ( ! empty( $certificates ) ) : ?>

                    <?php foreach ( $certificates as $certificate ) : ?>

                        <?php
                        $view_url = add_query_arg(
                            array(
                                'page'                => 'apollo-learning-certificates',
                                'certificate_student' => $certificate['user_id'],
                                'certificate_course'  => $certificate['course_id'],
                            ),
                            admin_url( 'admin.php' )
                        );
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php echo esc_html( $certificate['student_name'] ); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo esc_html( $certificate['student_email'] ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $certificate['course_title'] ); ?>
                            </td>

                            <td>

                                <?php
                                if ( $certificate['completion_date'] ) {

                                    echo esc_html(
                                        date_i18n(
                                            get_option( 'date_format' ),
                                            strtotime( $certificate['completion_date'] )
                                        )
                                    );

                                } else {

                                    echo '—';

                                }
                                ?>

                            </td>

                            <td>
                                <a
                                    href="<?php echo esc_url( $view_url ); ?>"
                                    class="button button-primary"
                                >
                                    View Certificate
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else : ?>

                    <tr>
                        <td colspan="5">
                            No certificates have been earned yet.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

    <?php
}


/**
 * APOLLO SECURE LESSON RESOURCE DOWNLOADS
 */

/**
 * Generate a secure download URL for a lesson resource.
 */
function apollo_get_lesson_resource_download_url( $lesson_id ) {

    $lesson_id = absint( $lesson_id );

    if ( ! $lesson_id ) {
        return '';
    }

    return add_query_arg(
        array(
            'action'    => 'apollo_download_lesson_resource',
            'lesson_id' => $lesson_id,
        ),
        admin_url( 'admin-post.php' )
    );
}

/**
 * Handle protected lesson resource downloads.
 */
function apollo_handle_lesson_resource_download() {

    $lesson_id = isset( $_GET['lesson_id'] )
        ? absint( $_GET['lesson_id'] )
        : 0;

    if ( ! $lesson_id ) {
        wp_die( 'Invalid lesson.', 'Access denied', array( 'response' => 403 ) );
    }

    // Confirm that the lesson exists and is published.
    $lesson = get_post( $lesson_id );

    if (
        ! $lesson ||
        'lesson' !== $lesson->post_type ||
        'publish' !== $lesson->post_status
    ) {
        wp_die( 'Lesson not found.', 'Not found', array( 'response' => 404 ) );
    }

    // Get the resource attachment ID from ACF.
    $attachment_id = absint(
        get_field( 'lesson_resources', $lesson_id )
    );

    if (
        ! $attachment_id ||
        'attachment' !== get_post_type( $attachment_id )
    ) {
        wp_die( 'Resource not found.', 'Not found', array( 'response' => 404 ) );
    }

    // Confirm the user is allowed to access this lesson.
    $course_id = absint(
        get_field( 'lesson_course', $lesson_id )
    );

    if ( ! $course_id ) {
        wp_die( 'Course not found.', 'Access denied', array( 'response' => 403 ) );
    }

    $is_preview = apollo_is_free_preview_lesson( $lesson_id );

    $has_access = (
        is_user_logged_in() &&
        apollo_user_has_course_access( get_current_user_id(), $course_id )
    );

    if ( ! $is_preview && ! $has_access ) {
        wp_die( 'You do not have permission to download this resource.', 'Access denied', array( 'response' => 403 ) );
    }

    // Resolve the actual file path.

    $file_path = get_attached_file( $attachment_id );

    $private_directory = wp_upload_dir()['basedir'] . '/apollo-private/';

    $real_file = realpath( $file_path );
    $real_private_directory = realpath( $private_directory );

    // Ensure the file is inside the protected directory.
    
    // Normalize Windows and Unix paths before comparing them.
    $normalized_file = $real_file
        ? wp_normalize_path( $real_file )
        : '';

    $normalized_private_directory = $real_private_directory
        ? trailingslashit( wp_normalize_path( $real_private_directory ) )
        : '';

    // Ensure the file is inside the protected directory.
    if (
        ! $real_file ||
        ! $real_private_directory ||
        strpos(
            $normalized_file,
            $normalized_private_directory
        ) !== 0 ||
        ! is_file( $real_file ) ||
        ! is_readable( $real_file )
    ) {
        wp_die(
            'Protected resource is unavailable.',
            'Not found',
            array( 'response' => 404 )
        );
    }


    // Send the file through PHP instead of exposing its URL.
    $file_name = basename( $real_file );
    $file_type = wp_check_filetype( $file_name );

    nocache_headers();

    header( 'Content-Type: ' . ( $file_type['type'] ?: 'application/octet-stream' ) );
    header( 'Content-Disposition: attachment; filename="' . rawurlencode( $file_name ) . '"' );
    header( 'Content-Length: ' . filesize( $real_file ) );
    header( 'X-Content-Type-Options: nosniff' );

    readfile( $real_file );
    exit;
}

add_action(
    'admin_post_apollo_download_lesson_resource',
    'apollo_handle_lesson_resource_download'
);

add_action(
    'admin_post_nopriv_apollo_download_lesson_resource',
    'apollo_handle_lesson_resource_download'
);

/**
 * ADMIN LEARNING REPORTS
 */

function apollo_admin_learning_reports_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    global $wpdb;

    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';
    $progress_table   = $wpdb->prefix . 'apollo_lesson_progress';

    /*
     * GET ALL ACTIVE ENROLLMENTS
     */

    $enrollments = $wpdb->get_results(
        "SELECT
            e.user_id,
            e.course_id,
            u.display_name,
            u.user_email,
            p.post_title AS course_title

        FROM $enrollment_table e

        LEFT JOIN {$wpdb->users} u
            ON e.user_id = u.ID

        LEFT JOIN {$wpdb->posts} p
            ON e.course_id = p.ID

        WHERE e.status = 'active'

        ORDER BY p.post_title ASC, u.display_name ASC"
    );

    /*
     * CALCULATE REPORT STATISTICS
     */

    $total_enrollments       = count( $enrollments );
    $total_completed_courses = 0;
    $total_in_progress       = 0;
    $total_completed_lessons = 0;

    $active_students = array();
    $in_progress_students = array();
    $course_reports = array();

    foreach ( $enrollments as $enrollment ) {

        $student_id = absint( $enrollment->user_id );
        $course_id  = absint( $enrollment->course_id );

        $active_students[ $student_id ] = true;

        if ( ! isset( $course_reports[ $course_id ] ) ) {

            $course_reports[ $course_id ] = array(
                'title'     => $enrollment->course_title,
                'enrolled'  => 0,
                'completed' => 0,
                'progress'  => 0,
            );
        }

        $course_reports[ $course_id ]['enrolled']++;

        if ( apollo_user_completed_course( $student_id, $course_id ) ) {

            $total_completed_courses++;

            $course_reports[ $course_id ]['completed']++;

        } else {

            $total_in_progress++;

            $in_progress_students[ $student_id ] = true;
        }
    }

    /*
     * COUNT COMPLETED LESSONS
     * Only count progress belonging to active enrollments.
     */

    $total_completed_lessons = (int) $wpdb->get_var(
        "SELECT COUNT(*)

        FROM $progress_table lp

        INNER JOIN $enrollment_table e
            ON lp.user_id = e.user_id
            AND lp.course_id = e.course_id

        WHERE e.status = 'active'"
    );

    /*
     * CALCULATE COURSE COMPLETION RATES
     */

    foreach ( $course_reports as $course_id => $report ) {

        if ( $report['enrolled'] > 0 ) {

            $course_reports[ $course_id ]['progress'] = round(
                ( $report['completed'] / $report['enrolled'] ) * 100
            );
        }
    }

    ?>

    <div class="wrap">
        
        <h1>Apollo Learning Reports</h1>
            <p>
        <a
            href="<?php echo esc_url(
                wp_nonce_url(
                    admin_url( 'admin-post.php?action=apollo_export_learning_report' ),
                    'apollo_export_learning_report'
                )
            ); ?>"
            class="button button-primary"
        >
            Download CSV Report
        </a>
    </p>
        <p>
            Overview of student enrollment, course completion,
            and learning activity.
        </p>

        <hr>

        <!-- SUMMARY STATISTICS -->

        <div style="
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
            gap:16px;
            margin:25px 0;
        ">

            <div style="background:#fff;padding:22px;border:1px solid #ddd;border-radius:8px;">
                <h3>Total Active Students</h3>
                <strong style="font-size:30px;">
                    <?php echo esc_html( count( $active_students ) ); ?>
                </strong>
            </div>

            <div style="background:#fff;padding:22px;border:1px solid #ddd;border-radius:8px;">
                <h3>Active Enrollments</h3>
                <strong style="font-size:30px;">
                    <?php echo esc_html( $total_enrollments ); ?>
                </strong>
            </div>

            <div style="background:#fff;padding:22px;border:1px solid #ddd;border-radius:8px;">
                <h3>Completed Courses</h3>
                <strong style="font-size:30px;">
                    <?php echo esc_html( $total_completed_courses ); ?>
                </strong>
            </div>

            <div style="background:#fff;padding:22px;border:1px solid #ddd;border-radius:8px;">
                <h3>Courses In Progress</h3>
                <strong style="font-size:30px;">
                    <?php echo esc_html( $total_in_progress ); ?>
                </strong>
            </div>

            <div style="background:#fff;padding:22px;border:1px solid #ddd;border-radius:8px;">
                <h3>Lessons Completed</h3>
                <strong style="font-size:30px;">
                    <?php echo esc_html( $total_completed_lessons ); ?>
                </strong>
            </div>

        </div>

        <!-- COURSE PERFORMANCE -->

        <h2>Course Performance</h2>

        <?php if ( empty( $course_reports ) ) : ?>

            <p>No active course enrollments found.</p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Active Enrollments</th>
                        <th>Completed</th>
                        <th>In Progress</th>
                        <th>Completion Rate</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ( $course_reports as $report ) : ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php echo esc_html( $report['title'] ); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo esc_html( $report['enrolled'] ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $report['completed'] ); ?>
                            </td>

                            <td>
                                <?php echo esc_html(
                                    $report['enrolled'] - $report['completed']
                                ); ?>
                            </td>

                            <td>

                                <strong>
                                    <?php echo esc_html( $report['progress'] ); ?>%
                                </strong>

                                <div style="
                                    background:#eee;
                                    height:8px;
                                    border-radius:10px;
                                    margin-top:6px;
                                    max-width:180px;
                                ">

                                    <div style="
                                        background:#C77750;
                                        height:8px;
                                        width:<?php echo esc_attr( $report['progress'] ); ?>%;
                                        border-radius:10px;
                                    "></div>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

        <!-- STUDENT ENROLLMENT DETAILS -->

        <h2 style="margin-top:35px;">Student Enrollment Details</h2>

        <?php if ( empty( $enrollments ) ) : ?>

            <p>No active student enrollments found.</p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ( $enrollments as $enrollment ) : ?>

                        <?php
                        $is_completed = apollo_user_completed_course(
                            absint( $enrollment->user_id ),
                            absint( $enrollment->course_id )
                        );
                        ?>

                        <tr>

                            <td>
                                <?php echo esc_html( $enrollment->display_name ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $enrollment->user_email ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $enrollment->course_title ); ?>
                            </td>

                            <td>

                                <?php if ( $is_completed ) : ?>

                                    <span style="color:#16803c;font-weight:600;">
                                        Completed
                                    </span>

                                <?php else : ?>

                                    <span style="color:#b7791f;font-weight:600;">
                                        In Progress
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

    <?php
}





/**
 * ADMIN LEARNING ACTIVITY
 */

function apollo_admin_learning_activity_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    global $wpdb;

    $progress_table   = $wpdb->prefix . 'apollo_lesson_progress';
    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';

    /*
     * RECENT LEARNING ACTIVITY
     */

    $recent_activity = $wpdb->get_results(
        "SELECT
            lp.user_id,
            lp.course_id,
            lp.lesson_id,
            lp.completed_at,
            u.display_name,
            p.post_title AS lesson_title,
            c.post_title AS course_title

        FROM $progress_table lp

        INNER JOIN $enrollment_table e
            ON lp.user_id = e.user_id
            AND lp.course_id = e.course_id

        LEFT JOIN {$wpdb->users} u
            ON lp.user_id = u.ID

        LEFT JOIN {$wpdb->posts} p
            ON lp.lesson_id = p.ID

        LEFT JOIN {$wpdb->posts} c
            ON lp.course_id = c.ID

        WHERE e.status = 'active'

        ORDER BY lp.completed_at DESC

        LIMIT 10"
    );

    /*
     * MOST ACTIVE STUDENTS
     */

    $most_active_students = $wpdb->get_results(
        "SELECT
            lp.user_id,
            u.display_name,
            u.user_email,
            COUNT(*) AS completed_lessons,
            MAX(lp.completed_at) AS last_activity

        FROM $progress_table lp

        INNER JOIN $enrollment_table e
            ON lp.user_id = e.user_id
            AND lp.course_id = e.course_id

        LEFT JOIN {$wpdb->users} u
            ON lp.user_id = u.ID

        WHERE e.status = 'active'

        GROUP BY lp.user_id, u.display_name, u.user_email

        ORDER BY completed_lessons DESC, last_activity DESC

        LIMIT 10"
    );

    /*
     * STUDENTS NEEDING ATTENTION
     *
     * No completed lesson in the last 30 days.
     */

    $students_needing_attention = $wpdb->get_results(
        "SELECT
            e.user_id,
            e.course_id,
            u.display_name,
            u.user_email,
            c.post_title AS course_title,
            MAX(lp.completed_at) AS last_activity

        FROM $enrollment_table e

        LEFT JOIN {$wpdb->users} u
            ON e.user_id = u.ID

        LEFT JOIN {$wpdb->posts} c
            ON e.course_id = c.ID

        LEFT JOIN $progress_table lp
            ON lp.user_id = e.user_id
            AND lp.course_id = e.course_id

        WHERE e.status = 'active'

        GROUP BY
            e.user_id,
            e.course_id,
            u.display_name,
            u.user_email,
            c.post_title

        HAVING
            last_activity IS NULL
            OR last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)

        ORDER BY last_activity ASC"
    );

    ?>

    <div class="wrap">

        <h1>Apollo Learning Activity</h1>

        <p>
            Monitor student engagement, lesson completions,
            and recent learning activity.
        </p>

        <hr>

        <!-- RECENT ACTIVITY -->

        <h2 style="margin-top:30px;">Recent Learning Activity</h2>

        <?php if ( empty( $recent_activity ) ) : ?>

            <p>No recent learning activity found.</p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Lesson</th>
                        <th>Course</th>
                        <th>Completed</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ( $recent_activity as $activity ) : ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php echo esc_html( $activity->display_name ); ?>
                                </strong>
                                <br>
                                <small>
                                    <?php
                                    echo esc_html(
                                        get_userdata( $activity->user_id )->user_email ?? ''
                                    );
                                    ?>
                                </small>
                            </td>

                            <td>
                                <?php echo esc_html( $activity->lesson_title ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $activity->course_title ); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    date_i18n(
                                        get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                                        strtotime( $activity->completed_at )
                                    )
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

        <!-- MOST ACTIVE STUDENTS -->

        <h2 style="margin-top:40px;">Most Active Students</h2>

        <?php if ( empty( $most_active_students ) ) : ?>

            <p>No completed lessons found.</p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Lessons Completed</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ( $most_active_students as $student ) : ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php echo esc_html( $student->display_name ); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo esc_html( $student->user_email ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $student->completed_lessons ); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    date_i18n(
                                        get_option( 'date_format' ),
                                        strtotime( $student->last_activity )
                                    )
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

        <!-- STUDENTS NEEDING ATTENTION -->

        <h2 style="margin-top:40px;">Students Needing Attention</h2>

        <p>
            Active enrollments with no recorded lesson completion
            during the last 30 days.
        </p>

        <?php if ( empty( $students_needing_attention ) ) : ?>

            <p>
                <span style="color:#16803c;font-weight:600;">
                    All active enrollments have recent lesson completion activity.
                </span>
            </p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th>Last Lesson Completion</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ( $students_needing_attention as $student ) : ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php echo esc_html( $student->display_name ); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo esc_html( $student->user_email ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $student->course_title ); ?>
                            </td>

                            <td>

                                <?php if ( $student->last_activity ) : ?>

                                    <span style="color:#b7791f;font-weight:600;">
                                        <?php
                                        echo esc_html(
                                            date_i18n(
                                                get_option( 'date_format' ),
                                                strtotime( $student->last_activity )
                                            )
                                        );
                                        ?>
                                    </span>

                                <?php else : ?>

                                    <span style="color:#b32d2e;font-weight:600;">
                                        No lessons completed
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

    <?php
}

/**
 * EXPORT LEARNING REPORTS TO CSV
 */

function apollo_export_learning_report_csv() {

    // Only administrators can export reports.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to export learning reports.' );
    }

    check_admin_referer( 'apollo_export_learning_report' );

    global $wpdb;

    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';
    $progress_table   = $wpdb->prefix . 'apollo_lesson_progress';

    // Retrieve active enrollments.
    $enrollments = $wpdb->get_results(
        "SELECT
            e.user_id,
            e.course_id,
            e.status,
            e.enrolled_at,
            u.display_name,
            u.user_email,
            p.post_title AS course_title

        FROM $enrollment_table e

        LEFT JOIN {$wpdb->users} u
            ON e.user_id = u.ID

        LEFT JOIN {$wpdb->posts} p
            ON e.course_id = p.ID

        WHERE e.status = 'active'

        ORDER BY p.post_title ASC, u.display_name ASC"
    );

    // Send CSV download headers.
    nocache_headers();

    header( 'Content-Type: text/csv; charset=utf-8' );
    header(
        'Content-Disposition: attachment; filename=apollo-learning-report-' .
        gmdate( 'Y-m-d' ) . '.csv'
    );

    $output = fopen( 'php://output', 'w' );

    // UTF-8 BOM helps Excel display names correctly.
    fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    // Prevent spreadsheet formula injection.
    $safe_csv_value = function ( $value ) {

        $value = (string) $value;

        if (
            preg_match( '/^[\s]*[=\+\-@\t\r]/', $value )
        ) {
            $value = "'" . $value;
        }

        return $value;
    };

    // CSV column headings.
    fputcsv(
        $output,
        array(
            'Student Name',
            'Email',
            'Course',
            'Enrollment Status',
            'Enrollment Date',
            'Completed Lessons',
            'Total Lessons',
            'Completion Percentage',
            'Course Status',
            'Last Learning Activity',
        )
    );

    foreach ( $enrollments as $enrollment ) {

        $student_id = absint( $enrollment->user_id );
        $course_id  = absint( $enrollment->course_id );

        // Get the lessons belonging to this course.
        $course_lessons = apollo_get_course_lessons( $course_id );

        $total_lessons = (
            $course_lessons instanceof WP_Query
        )
            ? count( $course_lessons->posts )
            : 0;

        // Count completed lessons for this student and course.
        $completed_lessons = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM $progress_table
                WHERE user_id = %d
                AND course_id = %d",
                $student_id,
                $course_id
            )
        );

        // Calculate completion percentage.
        $completion_percentage = 0;

        if ( $total_lessons > 0 ) {

            $completion_percentage = min(
                100,
                round(
                    ( $completed_lessons / $total_lessons ) * 100
                )
            );
        }

        // Get most recent lesson completion.
        $last_activity = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(completed_at)
                FROM $progress_table
                WHERE user_id = %d
                AND course_id = %d",
                $student_id,
                $course_id
            )
        );

        $course_status = (
            $total_lessons > 0
            && $completed_lessons >= $total_lessons
        )
            ? 'Completed'
            : 'In Progress';

        // Format dates for the spreadsheet.
        $enrollment_date = ! empty( $enrollment->enrolled_at )
            ? $enrollment->enrolled_at
            : '';

        $last_activity_date = ! empty( $last_activity )
            ? $last_activity
            : 'No activity';

        // Write the enrollment as one CSV row.
        fputcsv(
            $output,
            array(
                $safe_csv_value( $enrollment->display_name ),
                $safe_csv_value( $enrollment->user_email ),
                $safe_csv_value( $enrollment->course_title ),
                $safe_csv_value( $enrollment->status ),
                $safe_csv_value( $enrollment_date ),
                $completed_lessons,
                $total_lessons,
                $completion_percentage . '%',
                $course_status,
                $safe_csv_value( $last_activity_date ),
            )
        );
    }

    fclose( $output );

    exit;
}

add_action(
    'admin_post_apollo_export_learning_report',
    'apollo_export_learning_report_csv'
);

/**
 * STUDENT NOTIFICATIONS DATABASE TABLE
 */

function apollo_create_notifications_table() {

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = $wpdb->prefix . 'apollo_notifications';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        notification_type varchar(50) NOT NULL,
        title varchar(255) NOT NULL,
        message text NOT NULL,
        related_id bigint(20) unsigned NOT NULL DEFAULT 0,
        is_read tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY user_read (user_id,is_read),
        KEY user_created (user_id,created_at)
    ) $charset_collate;";

    dbDelta( $sql );

    update_option( 'apollo_notifications_db_version', '1.0.0' );
}


/*
 * Run the database upgrade automatically.
 */

function apollo_maybe_install_notifications_table() {

    $installed_version = get_option( 'apollo_notifications_db_version' );

    if ( '1.0.0' !== $installed_version ) {

        apollo_create_notifications_table();
    }
}

add_action(
    'plugins_loaded',
    'apollo_maybe_install_notifications_table',
    21
);


/**
 * CREATE A STUDENT NOTIFICATION
 */

function apollo_add_student_notification(
    $user_id,
    $notification_type,
    $title,
    $message,
    $related_id = 0
) {

    global $wpdb;

    $user_id = absint( $user_id );

    if ( ! $user_id || ! get_userdata( $user_id ) ) {
        return false;
    }

    $table_name = $wpdb->prefix . 'apollo_notifications';

    $result = $wpdb->insert(
        $table_name,
        array(
            'user_id'           => $user_id,
            'notification_type' => sanitize_key( $notification_type ),
            'title'             => sanitize_text_field( $title ),
            'message'           => sanitize_textarea_field( $message ),
            'related_id'        => absint( $related_id ),
            'is_read'           => 0,
            'created_at'        => current_time( 'mysql' ),
        ),
        array(
            '%d',
            '%s',
            '%s',
            '%s',
            '%d',
            '%d',
            '%s',
        )
    );

    return $result ? $wpdb->insert_id : false;
}

/**
 * NOTIFY ENROLLED STUDENTS WHEN A NEW LESSON IS PUBLISHED
 */

/**
 * NOTIFY ENROLLED STUDENTS WHEN A NEW LESSON IS PUBLISHED
 *
 * Runs after ACF has saved the lesson fields.
 */

function apollo_notify_students_new_lesson( $post_id ) {

    // Ignore revisions and non-numeric IDs.
    if ( ! is_numeric( $post_id ) ) {
        return;
    }

    $post_id = absint( $post_id );

    if ( get_post_type( $post_id ) !== 'lesson' ) {
        return;
    }

    // Only published lessons.
    if ( get_post_status( $post_id ) !== 'publish' ) {
        return;
    }

    // Prevent duplicate notifications.
    if ( get_post_meta(
        $post_id,
        '_apollo_lesson_notification_sent',
        true
    ) ) {
        return;
    }

    // ACF fields are now saved.
    $course_id = absint(
        get_field( 'lesson_course', $post_id )
    );

    if ( ! $course_id ) {
        error_log(
            'APOLLO NOTIFICATION: No course assigned to lesson ' . $post_id
        );

        return;
    }

    global $wpdb;

    $enrollment_table = $wpdb->prefix . 'apollo_enrollments';

    $student_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT user_id
            FROM $enrollment_table
            WHERE course_id = %d
            AND status = 'active'",
            $course_id
        )
    );

    $lesson_title = get_the_title( $post_id );
    $course_title = get_the_title( $course_id );

    foreach ( $student_ids as $student_id ) {

        apollo_add_student_notification(
            $student_id,
            'new_lesson',
            'New Lesson Available',
            sprintf(
                'A new lesson, "%s", has been added to %s.',
                $lesson_title,
                $course_title
            ),
            $post_id
        );
    }

    // Mark as processed to prevent duplicate notifications.
    update_post_meta(
        $post_id,
        '_apollo_lesson_notification_sent',
        1
    );

    error_log(
        'APOLLO NOTIFICATION: Processed lesson '
        . $post_id
        . ' for '
        . count( $student_ids )
        . ' active students.'
    );
}

add_action(
    'acf/save_post',
    'apollo_notify_students_new_lesson',
    20
);


/*
 * COURSE BOOKMARKS DATABASE TABLE
 */

function apollo_create_bookmarks_table() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'apollo_course_bookmarks';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        course_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_course (user_id, course_id),
        KEY user_id (user_id),
        KEY course_id (course_id)
    ) $charset_collate;";

    dbDelta( $sql );
}


/*
 * INSTALL BOOKMARKS TABLE
 */

function apollo_maybe_install_bookmarks_table() {

    $installed_version = get_option(
        'apollo_bookmarks_db_version'
    );

    if ( '1.0.0' !== $installed_version ) {

        apollo_create_bookmarks_table();

        update_option(
            'apollo_bookmarks_db_version',
            '1.0.0'
        );
    }
}

add_action(
    'plugins_loaded',
    'apollo_maybe_install_bookmarks_table',
    21
);


/*
 * CHECK WHETHER A COURSE IS BOOKMARKED
 */

function apollo_is_course_bookmarked( $user_id, $course_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_bookmarks';

    $bookmark_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
            FROM $table
            WHERE user_id = %d
            AND course_id = %d
            LIMIT 1",
            absint( $user_id ),
            absint( $course_id )
        )
    );

    return ! empty( $bookmark_id );
}


/*
 * ADD COURSE BOOKMARK
 */

function apollo_add_course_bookmark( $user_id, $course_id ) {

    global $wpdb;

    $user_id = absint( $user_id );
    $course_id = absint( $course_id );

    if (
        ! $user_id ||
        ! $course_id ||
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        return false;
    }

    $table = $wpdb->prefix . 'apollo_course_bookmarks';

    $result = $wpdb->insert(
        $table,
        array(
            'user_id' => $user_id,
            'course_id' => $course_id,
            'created_at' => current_time( 'mysql' ),
        ),
        array( '%d', '%d', '%s' )
    );

    // A duplicate bookmark is already effectively saved.
    if ( false === $result ) {

        return apollo_is_course_bookmarked(
            $user_id,
            $course_id
        );
    }

    return true;
}


/*
 * REMOVE COURSE BOOKMARK
 */

function apollo_remove_course_bookmark( $user_id, $course_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_bookmarks';

    $result = $wpdb->delete(
        $table,
        array(
            'user_id' => absint( $user_id ),
            'course_id' => absint( $course_id ),
        ),
        array( '%d', '%d' )
    );

    return false !== $result;
}


/*
 * HANDLE COURSE BOOKMARK ACTIONS
 */

function apollo_handle_course_bookmark() {

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to save courses.' );
    }

    $course_id = isset( $_POST['course_id'] )
        ? absint( wp_unslash( $_POST['course_id'] ) )
        : 0;

    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        wp_die( 'Invalid course.' );
    }

    check_admin_referer(
        'apollo_bookmark_course_' . $course_id
    );

    $user_id = get_current_user_id();

    $action = isset( $_POST['bookmark_action'] )
        ? sanitize_key( wp_unslash( $_POST['bookmark_action'] ) )
        : '';

    if ( 'add' === $action ) {

        apollo_add_course_bookmark(
            $user_id,
            $course_id
        );

    } elseif ( 'remove' === $action ) {

        apollo_remove_course_bookmark(
            $user_id,
            $course_id
        );

    } else {

        wp_die( 'Invalid bookmark action.' );
    }

    $redirect_url = wp_get_referer();

    if ( ! $redirect_url ) {
        $redirect_url = get_permalink( $course_id );
    }

    wp_safe_redirect( $redirect_url );

    exit;
}

add_action(
    'admin_post_apollo_bookmark_course',
    'apollo_handle_course_bookmark'
);


/*
 * COURSE BOOKMARK BUTTON
 */

function apollo_course_bookmark_button( $content ) {

    if (
        ! is_singular( 'course' ) ||
        ! in_the_loop() ||
        ! is_main_query()
    ) {
        return $content;
    }

    $course_id = get_the_ID();

    if ( ! is_user_logged_in() ) {

        $login_url = wp_login_url(
            get_permalink( $course_id )
        );

        $button = '<div class="apollo-bookmark-login">';

        $button .= '<a href="' . esc_url( $login_url ) . '">';
        $button .= 'Log in to save this course';
        $button .= '</a>';

        $button .= '</div>';

        return $content . $button;
    }

    $user_id = get_current_user_id();

    $is_bookmarked = apollo_is_course_bookmarked(
        $user_id,
        $course_id
    );

    ob_start();

    echo '<div class="apollo-course-bookmark">';

    echo '<form method="post" action="' .
        esc_url( admin_url( 'admin-post.php' ) ) .
        '">';

    echo '<input type="hidden" name="action" value="apollo_bookmark_course">';

    echo '<input type="hidden" name="course_id" value="' .
        esc_attr( $course_id ) .
        '">';

    echo '<input type="hidden" name="bookmark_action" value="' .
        ( $is_bookmarked ? 'remove' : 'add' ) .
        '">';

    echo wp_nonce_field(
        'apollo_bookmark_course_' . $course_id,
        '_wpnonce',
        true,
        false
    );

    echo '<button type="submit" class="apollo-bookmark-button">';

    if ( $is_bookmarked ) {
        echo 'Remove Bookmark';
    } else {
        echo 'Save Course';
    }

    echo '</button>';

    echo '</form>';

    echo '</div>';

    return $content . ob_get_clean();
}

add_filter(
    'the_content',
    'apollo_course_bookmark_button',
    35
);


/*
 * STUDENT SAVED COURSES DASHBOARD SECTION
 */

function apollo_student_saved_courses_section() {

    if ( ! is_user_logged_in() ) {
        return '';
    }

    global $wpdb;

    $user_id = get_current_user_id();

    $table = $wpdb->prefix . 'apollo_course_bookmarks';

    $saved_courses = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                bookmarks.course_id,
                bookmarks.created_at
            FROM $table AS bookmarks

            INNER JOIN {$wpdb->posts} AS courses
                ON courses.ID = bookmarks.course_id
                AND courses.post_type = 'course'
                AND courses.post_status = 'publish'

            WHERE bookmarks.user_id = %d

            ORDER BY bookmarks.created_at DESC",
            $user_id
        )
    );

    ob_start();

    echo '<section class="apollo-dashboard-saved-courses">';

    echo '<h2>My Saved Courses</h2>';

    echo '<p>Courses you have bookmarked for later.</p>';

    if ( empty( $saved_courses ) ) {

        echo '<div class="apollo-dashboard-saved-empty">';

        echo '<p>You have not saved any courses yet.</p>';

        echo '</div>';

    } else {

        echo '<div class="apollo-dashboard-saved-list">';

        foreach ( $saved_courses as $saved_course ) {

            $course_id = absint( $saved_course->course_id );

            $course_title = get_the_title( $course_id );

            echo '<div class="apollo-dashboard-saved-course">';

            if ( has_post_thumbnail( $course_id ) ) {

                echo '<div class="apollo-dashboard-saved-image">';

                echo get_the_post_thumbnail(
                    $course_id,
                    'medium',
                    array(
                        'alt' => esc_attr( $course_title ),
                    )
                );

                echo '</div>';
            }

            echo '<div class="apollo-dashboard-saved-details">';

            echo '<h3>' . esc_html( $course_title ) . '</h3>';

            echo '<a href="' .
                esc_url( get_permalink( $course_id ) ) .
                '">View Course</a>';

            echo '<form method="post" action="' .
                esc_url( admin_url( 'admin-post.php' ) ) .
                '">';

            echo '<input type="hidden" name="action" value="apollo_bookmark_course">';

            echo '<input type="hidden" name="course_id" value="' .
                esc_attr( $course_id ) .
                '">';

            echo '<input type="hidden" name="bookmark_action" value="remove">';

            echo wp_nonce_field(
                'apollo_bookmark_course_' . $course_id,
                '_wpnonce',
                true,
                false
            );

            echo '<button type="submit">Remove Bookmark</button>';

            echo '</form>';

            echo '</div>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</section>';

    return ob_get_clean();
}


/*
 * COURSE REVIEWS DATABASE TABLE
 */

function apollo_create_course_reviews_table() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'apollo_course_reviews';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        course_id bigint(20) unsigned NOT NULL,
        rating tinyint(1) unsigned NOT NULL,
        review_content text NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_course (user_id, course_id),
        KEY course_id (course_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";

    dbDelta( $sql );
}


/*
 * INSTALL COURSE REVIEWS TABLE
 */

function apollo_maybe_install_course_reviews_table() {

    $installed_version = get_option(
        'apollo_course_reviews_db_version'
    );

    if ( '1.0.0' !== $installed_version ) {

        apollo_create_course_reviews_table();

        update_option(
            'apollo_course_reviews_db_version',
            '1.0.0'
        );
    }
}

add_action(
    'plugins_loaded',
    'apollo_maybe_install_course_reviews_table',
    21
);


/*
 * HANDLE COURSE REVIEW SUBMISSION
 */

function apollo_handle_course_review_submission() {

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to submit a review.' );
    }

    $course_id = isset( $_POST['course_id'] )
        ? absint( wp_unslash( $_POST['course_id'] ) )
        : 0;

    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        wp_die( 'Invalid course.' );
    }

    check_admin_referer(
        'apollo_submit_course_review_' . $course_id
    );

    $user_id = get_current_user_id();

    // Only actively enrolled students can review.
    if (
        ! apollo_user_has_course_access(
            $user_id,
            $course_id
        )
    ) {
        wp_die( 'You must be enrolled in this course to submit a review.' );
    }

    $rating = isset( $_POST['rating'] )
        ? absint( wp_unslash( $_POST['rating'] ) )
        : 0;

    $review_content = isset( $_POST['review_content'] )
        ? sanitize_textarea_field(
            wp_unslash( $_POST['review_content'] )
        )
        : '';

    if ( $rating < 1 || $rating > 5 ) {
        wp_die( 'Please select a rating between 1 and 5 stars.' );
    }

    if ( empty( trim( $review_content ) ) ) {
        wp_die( 'Please write a review before submitting.' );
    }

    if ( strlen( $review_content ) > 3000 ) {
        wp_die( 'Your review must not exceed 3000 characters.' );
    }

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_reviews';

    $existing_review = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, status
            FROM $table
            WHERE user_id = %d
            AND course_id = %d
            LIMIT 1",
            $user_id,
            $course_id
        )
    );

    $now = current_time( 'mysql' );

    if ( $existing_review ) {

        // Editing an existing review sends it back for moderation.
        $result = $wpdb->update(
            $table,
            array(
                'rating'         => $rating,
                'review_content' => $review_content,
                'status'         => 'pending',
                'updated_at'     => $now,
            ),
            array(
                'id'      => absint( $existing_review->id ),
                'user_id' => $user_id,
            ),
            array( '%d', '%s', '%s', '%s' ),
            array( '%d', '%d' )
        );

    } else {

        $result = $wpdb->insert(
            $table,
            array(
                'user_id'        => $user_id,
                'course_id'      => $course_id,
                'rating'         => $rating,
                'review_content' => $review_content,
                'status'         => 'pending',
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    if ( false === $result ) {
        wp_die( 'Unable to save your review. Please try again.' );
    }

    $redirect_url = add_query_arg(
        'apollo_review_submitted',
        '1',
        get_permalink( $course_id )
    );

    wp_safe_redirect( $redirect_url );

    exit;
}

add_action(
    'admin_post_apollo_submit_course_review',
    'apollo_handle_course_review_submission'
);


/*
 * COURSE REVIEW FORM
 */

function apollo_course_review_form() {

    if ( ! is_singular( 'course' ) ) {
        return '';
    }

    $course_id = get_the_ID();

    if ( ! is_user_logged_in() ) {

        $login_url = wp_login_url( get_permalink( $course_id ) );

        return '<div class="apollo-review-login">
            <p>Please log in to review this course.</p>
            <a href="' . esc_url( $login_url ) . '">Log In</a>
        </div>';
    }

    $user_id = get_current_user_id();

    if (
        ! apollo_user_has_course_access(
            $user_id,
            $course_id
        )
    ) {
        return '<div class="apollo-review-locked">
            <p>You must be enrolled in this course to leave a review.</p>
        </div>';
    }

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_reviews';

    $existing_review = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT rating, review_content, status
            FROM $table
            WHERE user_id = %d
            AND course_id = %d
            LIMIT 1",
            $user_id,
            $course_id
        )
    );

    $rating = $existing_review
        ? absint( $existing_review->rating )
        : 5;

    $review_content = $existing_review
        ? $existing_review->review_content
        : '';

    ob_start();

    ?>

    <div class="apollo-course-review-form">

        <h3>
            <?php
            echo $existing_review
                ? 'Edit Your Review'
                : 'Write a Course Review';
            ?>
        </h3>

        <?php if ( isset( $_GET['apollo_review_submitted'] ) ) : ?>

            <p class="apollo-review-success">
                Thank you! Your review has been submitted
                and is awaiting approval.
            </p>

        <?php endif; ?>

        <?php if ( $existing_review && 'pending' === $existing_review->status ) : ?>

            <p>Your review is awaiting approval.</p>

        <?php endif; ?>

        <form method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

            <input type="hidden"
                name="action"
                value="apollo_submit_course_review">

            <input type="hidden"
                name="course_id"
                value="<?php echo esc_attr( $course_id ); ?>">

            <?php
            wp_nonce_field(
                'apollo_submit_course_review_' . $course_id
            );
            ?>

            <p>
                <label for="apollo-review-rating">
                    Your Rating
                </label>

                <select
                    id="apollo-review-rating"
                    name="rating"
                    required>

                    <option value="5" <?php selected( $rating, 5 ); ?>>
                        5 - Excellent
                    </option>

                    <option value="4" <?php selected( $rating, 4 ); ?>>
                        4 - Very Good
                    </option>

                    <option value="3" <?php selected( $rating, 3 ); ?>>
                        3 - Good
                    </option>

                    <option value="2" <?php selected( $rating, 2 ); ?>>
                        2 - Fair
                    </option>

                    <option value="1" <?php selected( $rating, 1 ); ?>>
                        1 - Poor
                    </option>

                </select>
            </p>

            <p>
                <label for="apollo-review-content">
                    Your Review
                </label>

                <textarea
                    id="apollo-review-content"
                    name="review_content"
                    rows="5"
                    maxlength="3000"
                    required><?php echo esc_textarea( $review_content ); ?></textarea>
            </p>

            <button type="submit">
                <?php
                echo $existing_review
                    ? 'Update Review'
                    : 'Submit Review';
                ?>
            </button>

        </form>

    </div>

    <?php

    return ob_get_clean();
}


/*
 * DISPLAY REVIEW FORM ON COURSE PAGES
 */

function apollo_append_course_review_form( $content ) {

    if ( is_singular( 'course' ) && in_the_loop() && is_main_query() ) {

        $content .= apollo_course_review_form();
    }

    return $content;
}

add_filter(
    'the_content',
    'apollo_append_course_review_form',
    45
);


/*
 * GET COURSE REVIEW STATISTICS
 */

function apollo_get_course_review_stats( $course_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_reviews';

    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COUNT(*) AS review_count,
                AVG(rating) AS average_rating
            FROM $table
            WHERE course_id = %d
            AND status = 'approved'",
            $course_id
        )
    );

    return array(
        'count' => absint( $stats->review_count ),
        'average' => $stats->average_rating
            ? round( (float) $stats->average_rating, 1 )
            : 0,
    );
}


/*
 * DISPLAY APPROVED COURSE REVIEWS
 */

function apollo_course_reviews_display() {

    if ( ! is_singular( 'course' ) ) {
        return '';
    }

    $course_id = get_the_ID();

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_reviews';

    $reviews = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT user_id, rating, review_content, created_at
            FROM $table
            WHERE course_id = %d
            AND status = 'approved'
            ORDER BY created_at DESC
            LIMIT 50",
            $course_id
        )
    );

    $stats = apollo_get_course_review_stats( $course_id );

    ob_start();

    ?>

    <section class="apollo-course-reviews">

        <h2>Student Reviews</h2>

        <?php if ( $stats['count'] > 0 ) : ?>

            <div class="apollo-review-summary">

                <strong>
                    <?php echo esc_html( $stats['average'] ); ?>
                    / 5
                </strong>

                <span>
                    <?php echo esc_html( $stats['count'] ); ?>
                    <?php echo 1 === $stats['count'] ? 'Review' : 'Reviews'; ?>
                </span>

            </div>

            <?php foreach ( $reviews as $review ) : ?>

                <?php
                $student = get_userdata( $review->user_id );

                $student_name = $student
                    ? $student->display_name
                    : 'Student';
                ?>

                <article class="apollo-review-item">

                    <div class="apollo-review-header">

                        <strong>
                            <?php echo esc_html( $student_name ); ?>
                        </strong>

                        <span class="apollo-review-rating">
                            <?php
                            echo esc_html(
                                str_repeat( '★', absint( $review->rating ) ) .
                                str_repeat( '☆', 5 - absint( $review->rating ) )
                            );
                            ?>
                        </span>

                    </div>

                    <p>
                        <?php
                        echo nl2br(
                            esc_html( $review->review_content )
                        );
                        ?>
                    </p>

                    <small>
                        <?php
                        echo esc_html(
                            wp_date(
                                get_option( 'date_format' ),
                                strtotime( $review->created_at )
                            )
                        );
                        ?>
                    </small>

                </article>

            <?php endforeach; ?>

        <?php else : ?>

            <p>No approved reviews yet. Be the first to share your experience!</p>

        <?php endif; ?>

    </section>

    <?php

    return ob_get_clean();
}


/*
 * DISPLAY REVIEWS AFTER THE REVIEW FORM
 */

function apollo_append_course_reviews_display( $content ) {

    if ( is_singular( 'course' ) && in_the_loop() && is_main_query() ) {

        $content .= apollo_course_reviews_display();
    }

    return $content;
}

add_filter(
    'the_content',
    'apollo_append_course_reviews_display',
    46
);


/*
 * HANDLE COURSE REVIEW MODERATION
 */

function apollo_handle_review_moderation() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to manage reviews.' );
    }

    $review_id = isset( $_GET['review_id'] )
        ? absint( $_GET['review_id'] )
        : 0;

    $action = isset( $_GET['review_action'] )
        ? sanitize_key( wp_unslash( $_GET['review_action'] ) )
        : '';

    if ( ! $review_id || ! in_array( $action, array( 'approve', 'reject', 'delete' ), true ) ) {
        wp_die( 'Invalid review action.' );
    }

    check_admin_referer(
        'apollo_moderate_review_' . $review_id
    );

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_reviews';

    if ( 'delete' === $action ) {

        $wpdb->delete(
            $table,
            array( 'id' => $review_id ),
            array( '%d' )
        );

    } else {

        $new_status = 'approve' === $action
            ? 'approved'
            : 'rejected';

        $wpdb->update(
            $table,
            array(
                'status'     => $new_status,
                'updated_at' => current_time( 'mysql' ),
            ),
            array(
                'id' => $review_id,
            ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    wp_safe_redirect(
        admin_url( 'admin.php?page=apollo-course-reviews&review_updated=1' )
    );

    exit;
}

add_action(
    'admin_post_apollo_moderate_review',
    'apollo_handle_review_moderation'
);


/*
 * REGISTER COURSE REVIEWS ADMIN PAGE
 */

function apollo_register_course_reviews_admin_page() {

    add_submenu_page(
        'apollo-learning',
        'Course Reviews',
        'Course Reviews',
        'manage_options',
        'apollo-course-reviews',
        'apollo_admin_course_reviews_page'
    );
}

add_action(
    'admin_menu',
    'apollo_register_course_reviews_admin_page'
);


/*
 * COURSE REVIEWS ADMIN PAGE
 */

function apollo_admin_course_reviews_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;

    $table = $wpdb->prefix . 'apollo_course_reviews';

    $status = isset( $_GET['status'] )
        ? sanitize_key( wp_unslash( $_GET['status'] ) )
        : 'pending';

    if ( ! in_array( $status, array( 'pending', 'approved', 'rejected', 'all' ), true ) ) {
        $status = 'pending';
    }

    if ( 'all' === $status ) {

        $reviews = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY created_at DESC"
        );

    } else {

        $reviews = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                WHERE status = %s
                ORDER BY created_at DESC",
                $status
            )
        );
    }

    $pending_count = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table WHERE status = 'pending'"
    );

    ?>

    <div class="wrap">

        <h1>Course Reviews</h1>

        <?php if ( isset( $_GET['review_updated'] ) ) : ?>

            <div class="notice notice-success is-dismissible">
                <p>Review updated successfully.</p>
            </div>

        <?php endif; ?>

        <p>
            <strong>Pending Reviews:</strong>
            <?php echo esc_html( $pending_count ); ?>
        </p>

        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=apollo-course-reviews&status=pending' ) ); ?>">Pending</a> |
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=apollo-course-reviews&status=approved' ) ); ?>">Approved</a> |
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=apollo-course-reviews&status=rejected' ) ); ?>">Rejected</a> |
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=apollo-course-reviews&status=all' ) ); ?>">All Reviews</a>
        </p>

        <?php if ( empty( $reviews ) ) : ?>

            <p>No reviews found in this section.</p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Course</th>
                        <th>Rating</th>
                        <th>Review</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ( $reviews as $review ) : ?>

                        <?php
                        $student = get_userdata( $review->user_id );
                        $course_title = get_the_title( $review->course_id );

                        $actions = array(
                            'approve' => 'Approve',
                            'reject'  => 'Reject',
                            'delete'  => 'Delete',
                        );
                        ?>

                        <tr>

                            <td>
                                <?php echo esc_html( $student ? $student->display_name : 'Unknown Student' ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $course_title ? $course_title : 'Deleted Course' ); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    str_repeat( '★', absint( $review->rating ) ) .
                                    str_repeat( '☆', 5 - absint( $review->rating ) )
                                );
                                ?>
                            </td>

                            <td>
                                <?php echo esc_html( $review->review_content ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( ucfirst( $review->status ) ); ?>
                            </td>

                            <td>
                                <?php echo esc_html( $review->created_at ); ?>
                            </td>

                            <td>

                                <?php foreach ( $actions as $action => $label ) : ?>

                                    <?php
                                    $url = add_query_arg(
                                        array(
                                            'action'        => 'apollo_moderate_review',
                                            'review_id'     => absint( $review->id ),
                                            'review_action' => $action,
                                        ),
                                        admin_url( 'admin-post.php' )
                                    );

                                    $url = wp_nonce_url(
                                        $url,
                                        'apollo_moderate_review_' . absint( $review->id )
                                    );
                                    ?>

                                    <a href="<?php echo esc_url( $url ); ?>"
                                        <?php echo 'delete' === $action ? 'onclick="return confirm(\'Permanently delete this review?\');"' : ''; ?>>

                                        <?php echo esc_html( $label ); ?>

                                    </a>

                                    <?php if ( 'delete' !== $action ) : ?>
                                        |
                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

    <?php
}

/*
 * COURSE RATING SHORTCODE
 */

function apollo_course_rating_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'course_id' => 0,
        ),
        $atts,
        'apollo_course_rating'
    );

    $course_id = absint( $atts['course_id'] );

    // Use the current course when no ID is provided.
    if ( ! $course_id ) {
        $course_id = get_the_ID();
    }

    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course'
    ) {
        return '';
    }

    $stats = apollo_get_course_review_stats( $course_id );

    ob_start();

    ?>

    <div class="apollo-course-rating">

        <?php if ( $stats['count'] > 0 ) : ?>

            <span class="apollo-rating-stars" aria-label="<?php echo esc_attr( $stats['average'] . ' out of 5 stars' ); ?>">

                <?php
                $full_stars = (int) round( $stats['average'] );

                echo esc_html(
                    str_repeat( '★', $full_stars ) .
                    str_repeat( '☆', 5 - $full_stars )
                );
                ?>

            </span>

            <strong class="apollo-rating-average">
                <?php echo esc_html( number_format( $stats['average'], 1 ) ); ?>
            </strong>

            <span class="apollo-rating-count">
                (<?php echo esc_html( $stats['count'] ); ?>
                <?php echo 1 === $stats['count'] ? 'review' : 'reviews'; ?>)
            </span>

        <?php else : ?>

            <span class="apollo-rating-empty">
                No reviews yet
            </span>

        <?php endif; ?>

    </div>

    <?php

    return ob_get_clean();
}

add_shortcode(
    'apollo_course_rating',
    'apollo_course_rating_shortcode'
);



/* =========================================================
   COURSE ENROLLMENT PURCHASE BUTTON
========================================================= */

/**
 * Display an Enroll Now button on a course page.
 *
 * Shortcode:
 * [apollo_course_enroll_button]
 * [apollo_course_enroll_button course_id="123"]
 */

function apollo_course_enroll_button_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'course_id' => 0,
        ),
        $atts,
        'apollo_course_enroll_button'
    );

    $course_id = absint( $atts['course_id'] );

    if ( ! $course_id ) {

        $current_post_id = get_the_ID();

        if (
            $current_post_id &&
            get_post_type( $current_post_id ) === 'course'
        ) {
            $course_id = $current_post_id;
        }
    }

    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        return '';
    }

    $course_url = get_permalink( $course_id );

    /*
     * CHECK WHETHER THE STUDENT IS ALREADY ENROLLED
     */

    if (
        is_user_logged_in() &&
        apollo_user_has_course_access(
            get_current_user_id(),
            $course_id
        )
    ) {
        return '<div class="apollo-course-enroll-wrap">
            <a class="apollo-course-enroll-button" href="' .
                esc_url( $course_url ) .
            '">Continue Learning</a>
        </div>';
    }

    /*
     * REQUIRE LOGIN BEFORE PURCHASE
     */

    if ( ! is_user_logged_in() ) {

        $login_url = wp_login_url( $course_url );

        return '<div class="apollo-course-enroll-wrap">
            <a class="apollo-course-enroll-button" href="' .
                esc_url( $login_url ) .
            '">Log In to Enroll</a>
        </div>';
    }

    /*
     * GET THE WOOCOMMERCE PRODUCT LINKED TO THIS COURSE
     */

    if ( ! function_exists( 'get_field' ) ) {
        return '';
    }

    $product_value = get_field(
        'course_woocommerce_product',
        $course_id
    );

    $product_id = $product_value instanceof WP_Post
        ? absint( $product_value->ID )
        : absint( $product_value );

    if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {

        if ( current_user_can( 'manage_options' ) ) {
            return '<p class="apollo-course-enroll-note">
                Please assign a WooCommerce product to this course.
            </p>';
        }

        return '';
    }

    $product = wc_get_product( $product_id );

    if ( ! $product || ! $product->is_purchasable() ) {

        if ( current_user_can( 'manage_options' ) ) {
            return '<p class="apollo-course-enroll-note">
                The WooCommerce product linked to this course is not available for purchase.
            </p>';
        }

        return '';
    }

    /*
     * DISPLAY THE PURCHASE BUTTON
     */

    ob_start();
    ?>

    <div class="apollo-course-enroll-wrap">

        <form method="post"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

            <input type="hidden"
                   name="action"
                   value="apollo_buy_course">

            <input type="hidden"
                   name="course_id"
                   value="<?php echo esc_attr( $course_id ); ?>">

            <?php
            wp_nonce_field(
                'apollo_buy_course_' . $course_id,
                'apollo_buy_course_nonce'
            );
            ?>

            <button type="submit"
                    class="apollo-course-enroll-button">
                Enroll Now
            </button>

        </form>

    </div>

    <?php
    return ob_get_clean();
}

add_shortcode(
    'apollo_course_enroll_button',
    'apollo_course_enroll_button_shortcode'
);


/* =========================================================
   HANDLE COURSE PURCHASE AND REDIRECT TO CHECKOUT
========================================================= */

function apollo_handle_course_purchase() {

    /*
     * REQUIRE A LOGGED-IN STUDENT
     */

    if ( ! is_user_logged_in() ) {
        auth_redirect();
    }

    $course_id = isset( $_POST['course_id'] )
        ? absint( wp_unslash( $_POST['course_id'] ) )
        : 0;

    if ( ! $course_id ) {
        wp_die( 'Invalid course.' );
    }

    /*
     * VERIFY THE FORM NONCE
     */

    check_admin_referer(
        'apollo_buy_course_' . $course_id,
        'apollo_buy_course_nonce'
    );

    /*
     * VALIDATE THE COURSE
     */

    if (
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        wp_die( 'This course is not available.' );
    }

    /*
     * DO NOT PURCHASE A COURSE THE STUDENT ALREADY HAS
     */

    if (
        apollo_user_has_course_access(
            get_current_user_id(),
            $course_id
        )
    ) {
        wp_safe_redirect( get_permalink( $course_id ) );
        exit;
    }

    /*
     * CHECK WOOCOMMERCE
     */

    if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
        wp_die( 'WooCommerce is not available.' );
    }

    /*
     * GET THE PRODUCT CONNECTED TO THE COURSE
     */

    $product_value = get_field(
        'course_woocommerce_product',
        $course_id
    );

    $product_id = $product_value instanceof WP_Post
        ? absint( $product_value->ID )
        : absint( $product_value );

    $product = $product_id
        ? wc_get_product( $product_id )
        : false;

    if (
        ! $product ||
        ! $product->is_purchasable() ||
        ! $product->is_in_stock()
    ) {
        wp_die( 'The product for this course is unavailable.' );
    }

    
    /*
     * SEND THE PRODUCT DIRECTLY TO CHECKOUT
     *
     * WooCommerce will add the product to its cart
     * during the normal checkout page request.
     */

    $checkout_url = add_query_arg(
        array(
            'add-to-cart' => $product_id,
            'quantity'    => 1,
        ),
        wc_get_checkout_url()
    );

    wp_safe_redirect( $checkout_url );
    exit;

}

add_action(
    'admin_post_apollo_buy_course',
    'apollo_handle_course_purchase'
);


/* =========================================================
   COURSE ADD TO CART BUTTON
========================================================= */

/**
 * Shortcode:
 * [apollo_course_add_to_cart_button]
 * [apollo_course_add_to_cart_button course_id="123"]
 */


function apollo_course_add_to_cart_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'course_id' => 0,
        ),
        $atts,
        'apollo_course_add_to_cart_button'
    );

    // Get the course ID.
    $course_id = absint( $atts['course_id'] );

    if ( ! $course_id ) {
        $current_post_id = get_the_ID();

        if (
            $current_post_id &&
            get_post_type( $current_post_id ) === 'course'
        ) {
            $course_id = $current_post_id;
        }
    }

    // Validate the course.
    if (
        ! $course_id ||
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        return '';
    }

    $course_url = get_permalink( $course_id );

    // Hide button if the student is already enrolled.
    if (
        is_user_logged_in() &&
        apollo_user_has_course_access(
            get_current_user_id(),
            $course_id
        )
    ) {
        return '';
    }

    // Require login before purchasing.
    if ( ! is_user_logged_in() ) {

        return '<div class="apollo-course-cart-wrap">
            <a class="apollo-course-cart-button"
               href="' . esc_url( wp_login_url( $course_url ) ) . '"
               aria-label="Log in to enroll"
               title="Log In to Enroll">
                Log In to Enroll
            </a>
        </div>';
    }

    // Check ACF availability.
    if ( ! function_exists( 'get_field' ) ) {
        return '';
    }

    // Get the linked WooCommerce product.
    $product_value = get_field(
        'course_woocommerce_product',
        $course_id
    );

    $product_id = $product_value instanceof WP_Post
        ? absint( $product_value->ID )
        : absint( $product_value );

    if (
        ! $product_id ||
        ! function_exists( 'wc_get_product' ) ||
        ! function_exists( 'wc_get_cart_url' )
    ) {
        return '';
    }

    $product = wc_get_product( $product_id );

    // Validate product availability.
    if (
        ! $product ||
        ! $product->is_purchasable() ||
        ! $product->is_in_stock()
    ) {
        return '';
    }

    // Build the native WooCommerce add-to-cart URL.
    $cart_url = add_query_arg(
        array(
            'add-to-cart' => $product_id,
            'quantity'    => 1,
        ),
        wc_get_cart_url()
    );

    // Display the cart icon.
    return '<div class="apollo-course-cart-wrap">
        <a href="' . esc_url( $cart_url ) . '"
           class="apollo-course-cart-button"
           aria-label="Add course to cart"
           title="Add to Cart">

            <svg xmlns="http://www.w3.org/2000/svg"
                 width="19"
                 height="19"
                 viewBox="0 0 24 24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1.7"
                 stroke-linecap="round"
                 stroke-linejoin="round"
                 aria-hidden="true">

                <path d="M6 7h12l1 14H5L6 7Z"/>
                <path d="M9 7a3 3 0 0 1 6 0"/>

            </svg>

        </a>
    </div>';
}

add_shortcode(
    'apollo_course_add_to_cart_button',
    'apollo_course_add_to_cart_shortcode'
);



/* =========================================================
   HANDLE ADD COURSE TO CART
========================================================= */

function apollo_handle_add_course_to_cart() {

    if ( ! is_user_logged_in() ) {
        auth_redirect();
    }

    $course_id = isset( $_POST['course_id'] )
        ? absint( wp_unslash( $_POST['course_id'] ) )
        : 0;

    if ( ! $course_id ) {
        wp_die( 'Invalid course.' );
    }

    check_admin_referer(
        'apollo_add_course_to_cart_' . $course_id,
        'apollo_add_course_to_cart_nonce'
    );

    if (
        get_post_type( $course_id ) !== 'course' ||
        get_post_status( $course_id ) !== 'publish'
    ) {
        wp_die( 'This course is not available.' );
    }

    if (
        apollo_user_has_course_access(
            get_current_user_id(),
            $course_id
        )
    ) {
        wp_safe_redirect( get_permalink( $course_id ) );
        exit;
    }

    if (
        ! function_exists( 'WC' ) ||
        ! function_exists( 'wc_get_product' )
    ) {
        wp_die( 'WooCommerce is not available.' );
    }

    $product_value = get_field(
        'course_woocommerce_product',
        $course_id
    );

    $product_id = $product_value instanceof WP_Post
        ? absint( $product_value->ID )
        : absint( $product_value );

    $product = $product_id
        ? wc_get_product( $product_id )
        : false;

    if (
        ! $product ||
        ! $product->is_purchasable() ||
        ! $product->is_in_stock()
    ) {
        wp_die( 'The product for this course is unavailable.' );
    }

    // Initialize the WooCommerce cart if necessary.
    if ( function_exists( 'wc_load_cart' ) ) {
        wc_load_cart();
    }

    if ( ! WC()->cart ) {
        wp_die( 'Unable to load the shopping cart.' );
    }

    $added = WC()->cart->add_to_cart( $product_id, 1 );

    if ( ! $added ) {
        wp_die( 'The course could not be added to your cart.' );
    }

    // Return to the page where the student clicked Add to Cart.
    $return_url = wp_get_referer();

    if ( ! $return_url ) {
        $return_url = $course_url = get_permalink( $course_id );
    }

    wp_safe_redirect( $return_url );
    exit;
}

add_action(
    'admin_post_apollo_add_course_to_cart',
    'apollo_handle_add_course_to_cart'
);

