import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import {
    AnalyticsHeader,
    AnalyticsState,
    ChartCard,
    DonutChart,
    LineChart,
    ScatterChart,
} from "../../components/statistics/AnalyticsCharts";

function AdminStatistics() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [refreshing, setRefreshing] = useState(false);

    const load = useCallback(async () => {
        setRefreshing(true);
        setError(null);
        try {
            const response = await api.get("/admin/statistics");
            setData(response.data);
        } catch (err) {
            console.error(err);
            setError("Could not load the platform analytics.");
        } finally {
            setRefreshing(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    return (
        <div className="min-h-screen px-5 py-10 sm:px-8 lg:px-10 lg:py-16">
            <AnalyticsHeader
                title="Platform Analytics"
                description="Visual analysis of membership growth, student engagement, and roadmap adoption across CS Mastery."
                updatedAt={data?.updated_at}
                onRefresh={load}
                refreshing={refreshing}
            />

            <AnalyticsState loading={!data && !error} error={error}>
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <ChartCard
                        title="User Registrations – Last 6 Months"
                        description="New student and professor accounts created in each month."
                    >
                        <LineChart data={data?.user_growth} />
                    </ChartCard>

                    <ChartCard
                        title="Current User Distribution"
                        description="How registered accounts are distributed across platform roles."
                    >
                        <DonutChart items={data?.role_distribution ?? []} centerLabel="Accounts" />
                    </ChartCard>

                    <ChartCard
                        title="Learning Engagement – Last 30 Days"
                        description="Daily learning actions recorded throughout the platform."
                    >
                        <LineChart data={data?.engagement_timeline} />
                    </ChartCard>

                    <ChartCard
                        title="Roadmap Size and Student Adoption"
                        description="Each bubble is a roadmap: horizontal position is module count, vertical position is active students, and bubble size represents completions."
                    >
                        <ScatterChart
                            items={data?.roadmap_engagement ?? []}
                            xKey="nodes"
                            yKey="students"
                            sizeKey="completions"
                            xLabel="Modules"
                            yLabel="Students"
                        />
                    </ChartCard>
                </div>
            </AnalyticsState>
        </div>
    );
}

export default AdminStatistics;
