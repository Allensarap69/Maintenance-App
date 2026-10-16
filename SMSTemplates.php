<?php
// Centralized SMS message templates for AutoCare Pro

class SMSTemplates {

    private static function replace($template, $values) {
        foreach ($values as $key => $value) {
            $template = str_replace("{" . $key . "}", $value, $template);
        }
        return $template;
    }

    public static function maintenanceReminderDate($brand, $model, $date) {
        $template = "Mindanao Eversure: Reminder: Your {brand} {model} is scheduled for preventive maintenance on {date}. Please schedule your service with Mindanao Eversure Corporation.";
        return self::replace($template, ['brand' => $brand, 'model' => $model, 'date' => $date]);
    }

    public static function maintenanceReminderKm($brand, $model, $interval, $currentMileage) {
        $template = "Mindanao Eversure: Your {brand} {model} is approaching its {interval} KM preventive maintenance. Current recorded mileage: {current_mileage} KM. Please schedule your service.";
        return self::replace($template, [
            'brand' => $brand,
            'model' => $model,
            'interval' => $interval,
            'current_mileage' => $currentMileage
        ]);
    }

    public static function overdueMaintenanceDate($brand, $model) {
        $template = "Mindanao Eversure: Your {brand} {model} is overdue for preventive maintenance. Please schedule your service with Mindanao Eversure Corporation to keep your motorcycle properly maintained.";
        return self::replace($template, ['brand' => $brand, 'model' => $model]);
    }

    public static function overdueMaintenanceKm($brand, $model, $interval, $currentMileage) {
        $template = "Mindanao Eversure: Your {brand} {model} is overdue for its {interval} KM preventive maintenance. Current recorded mileage: {current_mileage} KM. Please schedule your service.";
        return self::replace($template, [
            'brand' => $brand,
            'model' => $model,
            'interval' => $interval,
            'current_mileage' => $currentMileage
        ]);
    }

    public static function appointmentConfirmation($bookingId, $date, $time, $service) {
        $template = "Mindanao Eversure: Your motorcycle service appointment has been confirmed for {date} at {time}. Service: {service}. Please arrive on time.";
        return self::replace($template, [
            'booking_id' => $bookingId,
            'date' => $date,
            'time' => $time,
            'service' => $service
        ]);
    }

    public static function appointmentRejected($date, $time, $service) {
        $template = "Mindanao Eversure: We're sorry, your motorcycle service appointment for {date} at {time} (Service: {service}) has been declined. Please book another schedule or contact us for assistance.";
        return self::replace($template, [
            'date' => $date,
            'time' => $time,
            'service' => $service
        ]);
    }

    public static function serviceCompleted($date, $service) {
        $template = "Mindanao Eversure: Your motorcycle service has been completed on {date}. Service: {service}. Thank you for choosing Mindanao Eversure Corporation!";
        return self::replace($template, [
            'date' => $date,
            'service' => $service
        ]);
    }

    public static function emergencyAccepted($mechanicName) {
        $mechanic_text = !empty($mechanicName) ? " Mechanic {$mechanicName} has been assigned to assist you." : "";
        return "Mindanao Eversure: Your emergency service request has been accepted.{$mechanic_text} Please keep your phone accessible.";
    }

    public static function emergencyDeclined() {
        return "Mindanao Eversure: We're sorry, your emergency service request has been declined. Please contact us or submit another request for assistance.";
    }

    public static function emergencyCompleted() {
        return "Mindanao Eversure: Your emergency service has been completed. Thank you for choosing Mindanao Eversure Corporation!";
    }

    public static function appointmentReminder($date, $time, $service) {
        $template = "Mindanao Eversure: Reminder: Your motorcycle service appointment is tomorrow, {date} at {time}. Service: {service}. We look forward to serving you.";
        return self::replace($template, [
            'date' => $date,
            'time' => $time,
            'service' => $service
        ]);
    }

    public static function maintenanceDueReminder($brand, $model, $maintenance, $mileage) {
        $template = "Mindanao Eversure: Reminder: Your {brand} {model} is due for {maintenance} at {mileage} KM. Please book a service appointment with Mindanao Eversure Corporation.";
        return self::replace($template, [
            'brand' => $brand,
            'model' => $model,
            'maintenance' => $maintenance,
            'mileage' => $mileage
        ]);
    }

    public static function healthScoreAlert($brand, $model, $score, $maintenance) {
        $template = "Mindanao Eversure: Your {brand} {model} has a low health score of {score}/100. Recommended maintenance: {maintenance}. Please schedule your service with Mindanao Eversure Corporation.";
        return self::replace($template, [
            'brand' => $brand,
            'model' => $model,
            'score' => $score,
            'maintenance' => $maintenance
        ]);
    }

    public static function coachingNote($brand, $model, $note) {
        $template = "Mindanao Eversure: Service note for your {brand} {model}: {note}";
        return self::replace($template, ['brand' => $brand, 'model' => $model, 'note' => $note]);
    }

    public static function warrantyExpiration($brand, $model, $date) {
        $template = "Mindanao Eversure: Reminder: The warranty for your {brand} {model} will expire on {date}. Please contact Mindanao Eversure Corporation for applicable warranty concerns before the expiration date.";
        return self::replace($template, [
            'brand' => $brand,
            'model' => $model,
            'date' => $date
        ]);
    }
}
